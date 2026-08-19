<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Inventory\Application\ResolveTransferInfrastructure;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Event;

/**
 * RECEPCIONES DE COMPRA (D26, §3.2)
 *
 * **Aquí se cobra lo construido en la Iteración 2.** Se reciben «3 cajas de 12 kg» y el sistema guarda 36 000 g: la
 * presentación de compra existía desde entonces y ésta es la primera vez que sirve para algo.
 *
 * Confirmar dispara **tres efectos en tres módulos**, todos por evento (ADR-001):
 *
 *   1. `Inventory` registra el movimiento y crea el lote si hace falta.
 *   2. `Costing` captura el costo con `origin = purchase` — el valor del enum que llevaba una iteración entera sin un
 *      solo llamador.
 *   3. `Purchasing` deja la observación de precio con `source = receipt`, el otro valor que esperaba este momento.
 *
 * La prueba más importante es la del costo por unidad base: el precio de la caja NO es el costo del gramo, y confundirlos
 * daría un valor de inventario doce mil veces inflado.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que recibe',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Rocío',
        ownerPaternalSurname: 'Fuentes',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    // «La caja de 12 kg»: 12 000 g en la unidad base del artículo.
    $this->caja = ArticlePurchasePresentation::create([
        'article_id' => $this->jitomate->id,
        'name' => 'Caja de 12 kg',
        'quantity_in_base_unit' => '12000',
    ]);

    $this->beto = Supplier::create([
        'code' => 'DON-BETO',
        'legal_name' => 'Distribuidora del Bajío',
        'trade_name' => 'Don Beto',
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Captura una recepción por HTTP y devuelve su ULID. */
function recibe(array $lines = [], array $extra = []): string
{
    $porOmision = [[
        'article_ulid' => test()->jitomate->ulid,
        'presentation_ulid' => test()->caja->ulid,
        'quantity' => '3',
        'unit_price' => '480',
        'tax_rate' => '0',
    ]];

    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson('/api/v1/purchase-receipts', array_merge([
            'supplier_ulid' => test()->beto->ulid,
            'warehouse_ulid' => test()->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'lines' => $lines === [] ? $porOmision : $lines,
        ], $extra))
        ->assertCreated()
        ->json('data.ulid');
}

/** Confirma una recepción. */
function confirma(string $ulid): array
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/confirm")
        ->assertOk()
        ->json('data');
}

/** El saldo del jitomate en el almacén de la prueba. */
function saldoRecibido(): string
{
    return app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn (): string => ArticleStock::query()
            ->where('warehouse_id', test()->warehouse->id)
            ->where('article_id', test()->jitomate->id)
            ->value('quantity') ?? '0.0000',
    );
}

// ------------------------------------------------------------------- Captura

it('capturar NO mueve nada, y convierte la presentación a unidad base', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'supplier_document_number' => 'A-12345',
            'lines' => [[
                'article_ulid' => $this->jitomate->ulid,
                'presentation_ulid' => $this->caja->ulid,
                'quantity' => '3',
                'unit_price' => '480',
                'tax_rate' => '16',
            ]],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.folio', 'RC-1')
        ->assertJsonPath('data.supplier_document_number', 'A-12345')
        // Los tres totales son `null` en borrador: se calculan al confirmar. `null` y no cero, que diría que la factura
        // no cuesta nada.
        ->assertJsonPath('data.subtotal', null)
        ->assertJsonPath('data.total', null);

    $linea = $respuesta->json('data.lines.0');

    // AQUÍ se cobra la Iteración 2: 3 cajas × 12 000 g = 36 000 g, congelados.
    expect($linea['quantity'])->toBe('3.0000')
        ->and($linea['quantity_in_base_unit'])->toBe('36000.0000')
        // Los importes los calcula el servidor: 3 × 480 = 1440, más 16 % = 230.40.
        ->and($linea['line_subtotal'])->toBe('1440.00')
        ->and($linea['line_tax'])->toBe('230.40')
        ->and($linea['line_total'])->toBe('1670.40')
        ->and($linea['was_applied'])->toBeFalse();

    // Y nada se movió.
    expect(saldoRecibido())->toBe('0.0000');
});

it('sin presentación, la cantidad ya viene en unidad base', function () {
    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'quantity' => '5000',
        'unit_price' => '0.04',
        'tax_rate' => '0',
    ]]);

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/purchase-receipts/{$ulid}")
        ->assertOk();

    expect($respuesta->json('data.lines.0.quantity_in_base_unit'))->toBe('5000.0000')
        ->and($respuesta->json('data.lines.0.presentation'))->toBeNull();
});

it('rechaza una presentación que no es del artículo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $chile = Article::create([
        'name' => 'Chile',
        'base_unit_id' => $this->jitomate->base_unit_id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $bolsa = ArticlePurchasePresentation::create([
        'article_id' => $chile->id,
        'name' => 'Bolsa de 1 kg',
        'quantity_in_base_unit' => '1000',
    ]);

    app(TenantContext::class)->forget();

    // Con la presentación equivocada, la conversión daría una cantidad que no corresponde a nada — y ésa es la que
    // entraría al inventario.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'lines' => [[
                'article_ulid' => $this->jitomate->ulid,
                'presentation_ulid' => $bolsa->ulid,
                'quantity' => '3',
                'unit_price' => '480',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['lines.0.presentation_ulid']]);
});

it('no admite la misma factura del mismo proveedor dos veces', function () {
    recibe(extra: ['supplier_document_number' => 'A-12345']);

    // Es el error de captura más caro de todos: duplica existencia, duplica costo y descuadra el inventario contra la
    // realidad sin que nada avise. Lo impide un índice único, no una comprobación.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'supplier_document_number' => 'A-12345',
            'lines' => [[
                'article_ulid' => $this->jitomate->ulid,
                'quantity' => '100',
                'unit_price' => '1',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['supplier_document_number']]);
});

// ------------------------------------------------- Los tres efectos de confirmar

it('confirmar registra el movimiento con el COSTO POR UNIDAD BASE', function () {
    $ulid = recibe();

    $datos = confirma($ulid);

    expect($datos['status'])->toBe('confirmed')
        ->and($datos['subtotal'])->toBe('1440.00')
        ->and($datos['total'])->toBe('1440.00')
        ->and($datos['lines'][0]['was_applied'])->toBeTrue();

    expect(saldoRecibido())->toBe('36000.0000');

    app(TenantContext::class)->set($this->tenant->id);

    $movimiento = StockMovement::query()
        ->where('kind', StockMovementKind::PurchaseReceipt->value)
        ->sole();

    // LA aserción del paso: 1440 pesos ÷ 36 000 gramos = 0.04 el gramo. El precio de la CAJA era 480, y confundirlos
    // daría un valor de inventario doce mil veces inflado.
    expect($movimiento->unit_cost)->toBe('0.0400')
        ->and($movimiento->quantity)->toBe('36000.0000')
        ->and($movimiento->total_cost)->toBe('1440.00')
        ->and($movimiento->source_type)->toBe(PurchaseReceipt::class);
});

it('confirmar captura el costo con origen COMPRA', function () {
    $ulid = recibe();

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    // Aquí se estrena `CostOrigin::Purchase`: existía desde la Iteración 2 y no tenía un solo llamador. Hasta ahora todo
    // costo era manual.
    $costo = ArticleCost::query()
        ->where('article_id', $this->jitomate->id)
        ->where('origin', CostOrigin::Purchase->value)
        ->sole();

    expect($costo->unit_cost)->toBe('0.0400');

    // Y la proyección del costo vigente queda actualizada: es lo que inventarios lee para valuar (D152).
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0400');
});

it('confirmar deja la observación de precio del proveedor', function () {
    $ulid = recibe();

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    // §3.3: el historial se alimenta AUTOMÁTICAMENTE, porque «capturarlo a mano sería un catálogo que nadie
    // mantiene». Y con `source = receipt`, el otro valor que el paso 8 dejó sin llamador.
    $observacion = SupplierPrice::query()->sole();

    expect($observacion->source)->toBe(SupplierPriceSource::Receipt)
        ->and($observacion->unit_price)->toBe('0.0400')
        ->and($observacion->supplier_id)->toBe($this->beto->id)
        // Enlazada a la recepción: la FK que el paso 8 dejó sin constraint y este paso cerró.
        ->and($observacion->purchase_receipt_id)->not->toBeNull()
        // Y es un precio PAGADO, no una promesa: es la distinción que hace útil la comparación.
        ->and($observacion->source->isConfirmedPurchase())->toBeTrue();
});

// ----------------------------------------------------------------- El IVA

it('con IVA ACREDITABLE, el impuesto NO entra al costo', function () {
    // Por omisión. 3 cajas a 480 con 16 %: subtotal 1440, impuesto 230.40, total 1670.40.
    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'presentation_ulid' => $this->caja->ulid,
        'quantity' => '3',
        'unit_price' => '480',
        'tax_rate' => '16',
    ]]);

    $datos = confirma($ulid);

    expect($datos['tax_total'])->toBe('230.40')
        ->and($datos['total'])->toBe('1670.40')
        ->and($datos['vat_was_creditable'])->toBeTrue();

    app(TenantContext::class)->set($this->tenant->id);

    // El costo sale del SUBTOTAL: 1440 ÷ 36 000 = 0.04. El impuesto se recupera contra el IVA cobrado, así que sumarlo
    // inflaría el costo un 16 % y hundiría todos los márgenes.
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0400');
});

it('SIN acreditar, el impuesto SÍ entra al costo', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForTenant('purchasing.vat_is_creditable', false),
    );

    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'presentation_ulid' => $this->caja->ulid,
        'quantity' => '3',
        'unit_price' => '480',
        'tax_rate' => '16',
    ]]);

    $datos = confirma($ulid);

    // El criterio queda CONGELADO en la recepción. Sin esto, cambiar el ajuste volvería inexplicable el costo de las
    // recepciones viejas: se vería el neto y el impuesto, y no cuál de los dos había ido al costo.
    expect($datos['vat_was_creditable'])->toBeFalse();

    app(TenantContext::class)->set($this->tenant->id);

    // Ahora el costo sale del TOTAL: 1670.40 ÷ 36 000 = 0.0464. Para un negocio que no acredita, el impuesto pagado es
    // dinero que no vuelve.
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0464');
});

it('el PRECIO del proveedor es siempre el neto, acredite o no', function () {
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForTenant('purchasing.vat_is_creditable', false),
    );

    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'presentation_ulid' => $this->caja->ulid,
        'quantity' => '3',
        'unit_price' => '480',
        'tax_rate' => '16',
    ]]);

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    // El COSTO incluye el impuesto (0.0464) y el PRECIO DEL PROVEEDOR no (0.04). No es una inconsistencia: la pregunta
    // que el historial contesta es «¿me subió el precio?», y la tasa de IVA la fija la ley, no el proveedor. Una reforma
    // fiscal aparecería como una subida de todos los proveedores a la vez, que es lo que el reporte no debe decir.
    expect(SupplierPrice::query()->sole()->unit_price)->toBe('0.0400')
        ->and(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0464');
});

// -------------------------------------------------------------------- Lotes

it('confirmar CREA el lote, y capturarlo no', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->jitomate->update(['tracks_lots' => true]));

    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'presentation_ulid' => $this->caja->ulid,
        'quantity' => '3',
        'unit_price' => '480',
        'tax_rate' => '0',
        'lot_code' => 'L-2026-08',
        'expires_at' => now()->addMonth()->toDateString(),
    ]]);

    // Todavía NO existe: un borrador que creara lotes dejaría lotes huérfanos si nunca se confirma, y un lote huérfano
    // aparece en el selector de FEFO como si tuviera mercancía por surtir.
    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): int => ArticleLot::query()->count(),
    ))->toBe(0);

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    $lote = ArticleLot::query()->sole();

    expect($lote->code)->toBe('L-2026-08')
        ->and($lote->expires_at?->toDateString())->toBe(now()->addMonth()->toDateString());

    // Y el movimiento salió de ese lote.
    expect(StockMovement::query()->sole()->lot_id)->toBe($lote->id);
});

it('reusa el lote si ya existe con el mismo código', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->jitomate->update(['tracks_lots' => true]));

    foreach (['A-1', 'A-2'] as $factura) {
        $ulid = recibe([[
            'article_ulid' => $this->jitomate->ulid,
            'quantity' => '1000',
            'unit_price' => '0.04',
            'tax_rate' => '0',
            'lot_code' => 'L-MISMO',
        ]], ['supplier_document_number' => $factura]);

        confirma($ulid);
    }

    // UN lote, no dos: la misma partida puede llegar en dos facturas, y dos lotes con el mismo código repartirían su
    // existencia entre dos saldos y FEFO surtiría del equivocado.
    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): int => ArticleLot::query()->count(),
    ))->toBe(1);

    expect(saldoRecibido())->toBe('2000.0000');
});

it('rechaza capturar un lote en un artículo que no los lleva', function () {
    // Quien lo capturó cree haber registrado la caducidad, y el día que la mercancía se pase nadie va a entender por qué
    // el sistema no avisó. Mejor decirlo al capturar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'lines' => [[
                'article_ulid' => $this->jitomate->ulid,
                'quantity' => '1000',
                'unit_price' => '0.04',
                'lot_code' => 'L-INUTIL',
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['lines.0.lot_code']]);
});

// ----------------------------------------------------- Inmutabilidad y reversa

it('una recepción confirmada NO se cancela: se reversa', function () {
    $ulid = recibe();

    confirma($ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'REVERSA'));

    // Y confirmarla otra vez tampoco.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/confirm")
        ->assertStatus(422);

    expect(saldoRecibido())->toBe('36000.0000');
});

it('la reversa saca la mercancía con su tipo propio, y es un documento NUEVO', function () {
    $ulid = recibe();

    confirma($ulid);

    $reversa = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        // 201: la reversa es un documento nuevo, no una modificación del original.
        ->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.is_reversal', true)
        ->json('data');

    expect($reversa['reverses']['folio'])->toBe('RC-1')
        ->and($reversa['folio'])->toBe('RC-2');

    // La mercancía salió: 36 000 entraron y 36 000 salieron.
    expect(saldoRecibido())->toBe('0.0000');

    app(TenantContext::class)->set($this->tenant->id);

    // Con su tipo propio, `purchase_return`, y no un ajuste: la razón de la salida se CONOCE —la mercancía volvió al
    // proveedor— y un ajuste diría «salió algo y nadie sabe por qué» (D157).
    $devolucion = StockMovement::query()
        ->where('kind', StockMovementKind::PurchaseReturn->value)
        ->sole();

    expect($devolucion->quantity)->toBe('36000.0000')
        // Al costo con el que entró, congelado. Valuarla al costo vigente daría una devolución que gana o pierde dinero
        // según cuándo se haga.
        ->and($devolucion->unit_cost)->toBe('0.0400');

    // La original sigue CONFIRMADA. No se toca ni para marcarla: la corrección es un registro nuevo, igual que en el
    // kardex.
    expect(PurchaseReceipt::query()->where('ulid', $ulid)->sole()->status->value)->toBe('confirmed');
});

it('la reversa NO captura costo ni observación de precio', function () {
    $ulid = recibe();

    confirma($ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    // UN costo y UNA observación, los de la compra. Una devolución no fija precio: la mercancía se fue, no llegó.
    // Capturar un costo aquí diría que el proveedor vendió a ese precio otra vez, en la fecha de la devolución.
    expect(ArticleCost::query()->where('origin', CostOrigin::Purchase->value)->count())->toBe(1)
        ->and(SupplierPrice::query()->count())->toBe(1);

    // Y el costo vigente NO volvió al anterior: el historial es inmutable, y durante el tiempo que ese costo estuvo
    // vigente se valuaron movimientos con él. Borrarlo volvería inexplicables esas valuaciones.
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0400');
});

it('una recepción se reversa UNA sola vez', function () {
    $ulid = recibe();

    confirma($ulid);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        ->assertCreated();

    // Reversarla otra vez sacaría del inventario mercancía que ya salió. Lo impide un índice único además de la
    // comprobación.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'ya está reversada'));

    expect(saldoRecibido())->toBe('0.0000');
});

it('una reversa no se reversa', function () {
    $ulid = recibe();

    confirma($ulid);

    $reversaUlid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        ->assertCreated()
        ->json('data.ulid');

    // Para volver a meter la mercancía se captura una recepción nueva, con el precio y la fecha reales en lugar de
    // copiar los de la compra original.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$reversaUlid}/reverse")
        ->assertStatus(422);
});

it('un borrador SÍ se cancela, y no mueve nada', function () {
    $ulid = recibe();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(saldoRecibido())->toBe('0.0000');

    // Y ya no se confirma.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/confirm")
        ->assertStatus(422);
});

// ------------------------------------------------------------- Idempotencia

it('reintentar el evento NO duplica movimientos ni costos', function () {
    $ulid = recibe();

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);
    $recepcion = PurchaseReceipt::query()->where('ulid', $ulid)->sole();
    app(TenantContext::class)->forget();

    // Es lo que hace seguro reintentar cuando un oyente falló: los tres efectos llevan llave de idempotencia por línea.
    // Sin ellas, un reintento duplicaría existencia y dejaría dos costos en un historial que no se puede limpiar.
    PurchaseReceiptConfirmed::dispatch($recepcion);
    PurchaseReceiptConfirmed::dispatch($recepcion);

    expect(saldoRecibido())->toBe('36000.0000');

    app(TenantContext::class)->set($this->tenant->id);

    expect(StockMovement::query()->count())->toBe(1)
        ->and(ArticleCost::query()->where('origin', CostOrigin::Purchase->value)->count())->toBe(1);
});

// -------------------------------------------------------------------- Listado

it('lista las recepciones con sus filtros', function () {
    $borrador = recibe(extra: ['supplier_document_number' => 'A-1']);
    $confirmada = recibe(extra: ['supplier_document_number' => 'A-2']);

    confirma($confirmada);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/purchase-receipts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/purchase-receipts?only_drafts=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $borrador);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/purchase-receipts?status=confirmed')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/purchase-receipts?supplier={$this->beto->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Por el folio de la factura del proveedor: es lo que alguien tiene en la mano al buscar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/purchase-receipts?search=A-2')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ----------------------------------------------------------- Autorización

it('el almacenista CAPTURA recepciones y no las confirma', function () {
    $ulid = recibe();

    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $this->owner->syncRoles([$almacenista]);
    app(TenantContext::class)->forget();

    // Captura: recibe la mercancía y tiene la factura en la mano.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $this->warehouse->ulid,
            'received_at' => now()->toDateString(),
            'supplier_document_number' => 'A-999',
            'lines' => [['article_ulid' => $this->jitomate->ulid, 'quantity' => '100', 'unit_price' => '1']],
        ])
        ->assertCreated();

    // No confirma: aplicar mueve existencia y FIJA EL COSTO del que salen todos los precios sugeridos. Misma frontera
    // que cerrar un conteo (D179).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/confirm")
        ->assertForbidden();

    // Ni reversa.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson("/api/v1/purchase-receipts/{$ulid}/reverse")
        ->assertForbidden();
});

it('el mesero no ve recepciones', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/purchase-receipts')
        ->assertForbidden();
});

it('no se recibe en el almacén de tránsito', function () {
    $transito = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Warehouse => app(ResolveTransferInfrastructure::class)
            ->transitWarehouse(),
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/purchase-receipts', [
            'supplier_ulid' => $this->beto->ulid,
            'warehouse_ulid' => $transito->ulid,
            'received_at' => now()->toDateString(),
            'lines' => [['article_ulid' => $this->jitomate->ulid, 'quantity' => '100', 'unit_price' => '1']],
        ])
        ->assertStatus(422);
});

// ------------------------------- Lo que encontró el navegador (paso 11)

it('un precio que NO cabe en la columna se omite, y la recepción se confirma igual', function () {
    // Encontrado confirmando una recepción de verdad: 160 pesos entre 60 000 000 de gramos son 0.0000027 el gramo, que
    // en un `DECIMAL(12,4)` se redondea a cero. `RecordSupplierPrice` lo rechazaba —con razón para la captura a mano— y
    // el rechazo subía hasta la petición.
    //
    // La factura es CORRECTA: lo que no cabe es el número derivado. Así que se omite la observación y la confirmación
    // procede, que es la única lectura honesta del caso.
    app(TenantContext::class)->set($this->tenant->id);

    $granel = ArticlePurchasePresentation::create([
        'article_id' => $this->jitomate->id,
        'name' => 'Pipa de 60 toneladas',
        'quantity_in_base_unit' => '60000000',
    ]);

    app(TenantContext::class)->forget();

    $ulid = recibe([[
        'article_ulid' => $this->jitomate->ulid,
        'presentation_ulid' => $granel->ulid,
        'quantity' => '1',
        'unit_price' => '160',
        'tax_rate' => '0',
    ]]);

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    // La mercancía SÍ entró y el costo SÍ se capturó: son los efectos que dan sentido a «confirmada».
    expect(saldoRecibido())->toBe('60000000.0000')
        ->and(ArticleCost::query()->where('origin', CostOrigin::Purchase->value)->count())->toBe(1)
        // Y la observación de precio se omitió, porque un cero envenenaría la comparación entre proveedores (D203).
        ->and(SupplierPrice::query()->count())->toBe(0);
});

it('si un efecto falla DESPUÉS del commit, la confirmación no miente', function () {
    // El defecto más grave que encontró la verificación en el navegador: un oyente lanzaba, la petición respondía 422, y
    // la base tenía la recepción confirmada con su movimiento y su costo. Quien confirmó creía que no había pasado nada.
    //
    // Ahora el fallo se registra y no se propaga: la confirmación ya está comprometida y decir que falló invita a
    // repetirla. El estado incompleto es detectable desde el documento (`was_applied` por renglón) y los tres efectos
    // son idempotentes, así que volver a despachar el evento repara lo que falte.
    Event::listen(PurchaseReceiptConfirmed::class, function (): void {
        throw new RuntimeException('Un oyente cualquiera que falla');
    });

    $ulid = recibe();

    // 200, no 500: la recepción se confirmó de verdad.
    $datos = confirma($ulid);

    expect($datos['status'])->toBe('confirmed');

    // Y los oyentes registrados ANTES del que falla hicieron su trabajo.
    expect(saldoRecibido())->toBe('36000.0000');
});

it('el costo de una recepción de HOY gana al capturado antes ese mismo día', function () {
    // Encontrado valuando existencias en el navegador: la pantalla mostraba el inventario a 0.0320 —el costo que el
    // sembrador de demostración había capturado minutos antes— y no a 0.0400, el de la compra que acababa de confirmar.
    //
    // La causa no era la pantalla: `received_at` es una FECHA, y sellar el costo a su medianoche hacía que la compra
    // quedara SIEMPRE por detrás de cualquier captura del mismo día. La regla de que un costo retroactivo no pisa el
    // vigente es correcta; se estaba disparando por un artefacto de precisión.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(CaptureArticleCost::class)->atUnitCost(
        $this->jitomate,
        '0.0320',
    ));

    $ulid = recibe();

    confirma($ulid);

    app(TenantContext::class)->set($this->tenant->id);

    // 1440 ÷ 36 000 = 0.04, y ES el vigente: la compra ocurrió después de la captura manual.
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0400');
});

it('el costo de una recepción VIEJA no pisa el vigente', function () {
    // La otra mitad, y la que un arreglo perezoso rompería: sellar siempre con `now()` haría que confirmar hoy una
    // recepción de la semana pasada pisara el costo vigente, que sí es más reciente. La regla de §7 es que un costo
    // retroactivo se guarda en el historial y no cambia el vigente.
    $ulid = recibe(extra: ['received_at' => now()->subWeek()->toDateString()]);

    confirma($ulid);

    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(CaptureArticleCost::class)->atUnitCost(
        $this->jitomate,
        '0.0500',
    ));

    app(TenantContext::class)->set($this->tenant->id);

    // La captura manual de HOY manda sobre la recepción de la semana pasada.
    expect(ArticleCurrentCost::query()->where('article_id', $this->jitomate->id)->value('unit_cost'))
        ->toBe('0.0500');
});
