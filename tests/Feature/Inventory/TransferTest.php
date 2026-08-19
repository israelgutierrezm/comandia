<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Application\ResolveTransferInfrastructure;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;

/**
 * TRANSFERENCIAS ENTRE ALMACENES (D25, §6.2)
 *
 * La prueba central es la de la aritmética: **cuando salen 100 y llegan 95, el inventario total tiene que bajar
 * exactamente 5**, y cada uno de los cinco movimientos tiene que poder explicarse solo.
 *
 * Ahí es donde se ve por qué existe el almacén de tránsito. Sin él, el origen baja 100, el destino sube 95 y no hay
 * ningún movimiento que diga dónde quedaron los 5 — así que la pérdida no aparecería en el reporte de mermas, que
 * D168 definió como un filtro sobre el kardex. Y la alternativa de recibir los 100 en destino y mermar 5 ahí cuadra,
 * pero escribe en el kardex del destino una entrada de mercancía que nunca llegó.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con dos sucursales',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Amelia',
        ownerPaternalSurname: 'Cortés',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->origin = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    // La segunda sucursal con su almacén: hace falta para que haya a dónde transferir.
    $this->otherBranch = Branch::factory()->create(['name' => 'Sucursal Norte']);

    $this->destination = Warehouse::create([
        'branch_id' => $this->otherBranch->id,
        'kind' => WarehouseKind::Branch,
        'code' => 'ALM-NORTE',
        'name' => 'Almacén Norte',
        'status' => 'active',
    ]);

    $kilo = Unit::query()->where('code', 'kg')->firstOrFail();

    $this->arroz = Article::create([
        'name' => 'Arroz',
        'base_unit_id' => $kilo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Mete existencia en el origen sin pasar por HTTP. */
function surte(string $quantity, ?string $unitCost = null): void
{
    app(TenantContext::class)->runFor(test()->tenant->id, function () use ($quantity, $unitCost): void {
        if ($unitCost !== null) {
            app(CaptureArticleCost::class)
                ->atUnitCost(test()->arroz, $unitCost);
        }

        app(RecordStockMovement::class)->record(
            warehouse: test()->origin,
            article: test()->arroz,
            kind: StockMovementKind::PurchaseReceipt,
            quantity: $quantity,
        );
    });
}

/** Solicita una transferencia por HTTP y devuelve su ULID. */
function solicita(string $quantity = '100', array $extra = []): string
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson('/api/v1/transfers', array_merge([
            'origin_warehouse_ulid' => test()->origin->ulid,
            'destination_warehouse_ulid' => test()->destination->ulid,
            'lines' => [['article_ulid' => test()->arroz->ulid, 'quantity' => $quantity]],
        ], $extra))
        ->assertCreated()
        ->json('data.ulid');
}

/** El saldo del artículo en un almacén. */
function saldoEnAlmacen(Warehouse $warehouse): string
{
    return app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn (): string => ArticleStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('article_id', test()->arroz->id)
            ->value('quantity') ?? '0.0000',
    );
}

function almacenDeTransito(): Warehouse
{
    return app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn (): Warehouse => app(ResolveTransferInfrastructure::class)->transitWarehouse(),
    );
}

// ------------------------------------------------------------------ Solicitud

it('solicitar NO mueve mercancía, sólo crea el documento con su folio', function () {
    surte('200');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $this->origin->ulid,
            'destination_warehouse_ulid' => $this->destination->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
            'notes' => 'Nos quedamos sin arroz el sábado',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.folio', 'TR-1')
        ->assertJsonPath('data.lines.0.requested_quantity', '100.0000')
        // Las tres cantidades viajan siempre. `null` es «todavía no pasó», que es lo que la UI necesita para saber
        // si pintar un campo de captura o un dato.
        ->assertJsonPath('data.lines.0.shipped_quantity', null)
        ->assertJsonPath('data.lines.0.received_quantity', null)
        ->assertJsonPath('data.lines.0.transit_difference', null);

    // Quién lo pidió queda sellado desde el primer paso.
    expect($respuesta->json('data.steps.requested.at'))->not->toBeNull()
        ->and($respuesta->json('data.steps.authorized'))->toBeNull()
        // Los estados posibles los calcula el servidor: si el cliente los dedujera, tendría su propia copia de la
        // máquina de estados y se desincronizaría en la primera iteración (la lección de D139).
        ->and($respuesta->json('data.allowed_next'))->toContain('shipped')
        ->and($respuesta->json('data.allowed_next'))->toContain('cancelled');

    // Y nada se movió: el saldo del origen sigue completo.
    expect(saldoEnAlmacen($this->origin))->toBe('200.0000');
});

it('el folio es consecutivo por sucursal', function () {
    surte('500');

    expect(solicita('10'))->not->toBeEmpty();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $this->origin->ulid,
            'destination_warehouse_ulid' => $this->destination->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '10']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.folio', 'TR-2');
});

it('rechaza transferirse a sí mismo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $this->origin->ulid,
            'destination_warehouse_ulid' => $this->origin->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '10']],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['destination_warehouse_ulid']]);
});

// ---------------------------------------------------- La aritmética completa

it('salen 100 y llegan 100: cuatro movimientos y tránsito en cero', function () {
    surte('200', '30.0000');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'shipped');

    // Enviada: salió del origen y está en tránsito. No en el destino — todavía va en el camión.
    expect(saldoEnAlmacen($this->origin))->toBe('100.0000')
        ->and(saldoEnAlmacen(almacenDeTransito()))->toBe('100.0000')
        ->and(saldoEnAlmacen($this->destination))->toBe('0.0000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/receive", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.lines.0.transit_difference', '0.0000');

    expect(saldoEnAlmacen($this->origin))->toBe('100.0000')
        ->and(saldoEnAlmacen($this->destination))->toBe('100.0000')
        // Tránsito vuelve a CERO, y esta aserción vale más de lo que parece: si la llave de idempotencia del envío
        // y la de la recepción fueran la misma, los movimientos de la recepción se tomarían por un reintento del
        // envío, no moverían nada, y la mercancía se quedaría en tránsito para siempre.
        ->and(saldoEnAlmacen(almacenDeTransito()))->toBe('0.0000');

    app(TenantContext::class)->set($this->tenant->id);

    // Cuatro movimientos de transferencia, dos de salida y dos de entrada.
    $movimientos = StockMovement::query()
        ->whereIn('kind', [StockMovementKind::TransferOut->value, StockMovementKind::TransferIn->value])
        ->get();

    expect($movimientos)->toHaveCount(4)
        ->and($movimientos->pluck('total_cost')->unique()->all())->toBe(['3000.00']);
});

it('salen 100 y llegan 95: el inventario baja EXACTAMENTE 5, y la merma queda en tránsito', function () {
    surte('200', '30.0000');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/receive", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '95']],
        ])
        ->assertOk()
        // Estado propio y no una bandera sobre «recibida»: cambia lo que ocurrió, no cómo se muestra.
        ->assertJsonPath('data.status', 'received_with_differences')
        ->assertJsonPath('data.lines.0.transit_difference', '5.0000');

    expect($respuesta->json('data.lines.0.shipped_quantity'))->toBe('100.0000')
        ->and($respuesta->json('data.lines.0.received_quantity'))->toBe('95.0000');

    // LA aserción de la prueba: 200 − 5 = 195 en todo el negocio. El origen NO se carga dos veces.
    expect(saldoEnAlmacen($this->origin))->toBe('100.0000')
        ->and(saldoEnAlmacen($this->destination))->toBe('95.0000')
        ->and(saldoEnAlmacen(almacenDeTransito()))->toBe('0.0000');

    app(TenantContext::class)->set($this->tenant->id);

    // La merma está en TRÁNSITO, con el motivo del sistema. Ponerla en el origen sería un doble cargo: el origen ya
    // bajó las 100 que subieron al camión, y restarle otras 5 dejaría el inventario 105 abajo cuando se perdieron 5.
    $merma = StockMovement::query()
        ->where('kind', StockMovementKind::Waste->value)
        ->sole();

    expect($merma->warehouse_id)->toBe(almacenDeTransito()->id)
        ->and($merma->quantity)->toBe('5.0000')
        ->and($merma->wasteReason->name)->toBe(ResolveTransferInfrastructure::TRANSIT_DIFFERENCE_REASON)
        ->and($merma->wasteReason->is_system)->toBeTrue()
        // Y aparece en el reporte de mermas, que es un filtro sobre el kardex (D168). Ésa era la promesa.
        ->and($merma->source_type)->toBe(Transfer::class);
});

it('se puede enviar menos de lo pedido: es la respuesta «no había»', function () {
    surte('200');

    $ulid = solicita('100');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '60']],
        ])
        ->assertOk();

    // Las tres cantidades juntas contestan «se pidió 100, se mandó 60»: sin las tres, esa distinción se pierde y
    // nadie sabe si hay que pedir mejor o surtir mejor.
    expect($respuesta->json('data.lines.0.requested_quantity'))->toBe('100.0000')
        ->and($respuesta->json('data.lines.0.shipped_quantity'))->toBe('60.0000');

    expect(saldoEnAlmacen($this->origin))->toBe('140.0000');
});

it('NO se puede enviar más de lo pedido', function () {
    surte('200');

    $ulid = solicita('100');

    // Para mandar más, se pide más. Si no, la cantidad solicitada dejaría de servir para saber si se pidió poco o
    // se surtió poco.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '150']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'más de lo que se pidió'));

    expect(saldoEnAlmacen($this->origin))->toBe('200.0000');
});

it('NO se puede recibir más de lo que salió', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();

    // Recibir más de lo que salió haría que el sistema inventara existencia que nunca salió de ningún lado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/receive", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '120']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'más de lo que salió'));

    expect(saldoEnAlmacen(almacenDeTransito()))->toBe('100.0000');
});

it('una transferencia sin nada enviado no se envía', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '0']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'cancélala'));
});

// --------------------------------------------------- Máquina de estados

it('cancelar antes de enviar sí, después no', function () {
    surte('200');

    $primera = solicita('10');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$primera}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.allowed_next', []);

    $segunda = solicita('10');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$segunda}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '10']],
        ])
        ->assertOk();

    // Ya está en un camión: el único cierre posible es recibirla.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$segunda}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'tránsito'));
});

it('una transferencia recibida no se vuelve a recibir ni a enviar', function () {
    surte('200');

    $ulid = solicita('100');

    foreach (['ship', 'receive'] as $paso) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/transfers/{$ulid}/{$paso}", [
                'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
            ])
            ->assertOk();
    }

    // Corregir una transferencia recibida es hacer otra en sentido contrario: sus movimientos ya están en el
    // kardex, que es inmutable.
    foreach (['ship', 'receive'] as $paso) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/transfers/{$ulid}/{$paso}", [
                'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
            ])
            ->assertStatus(422);
    }

    expect(saldoEnAlmacen($this->destination))->toBe('100.0000');
});

// ------------------------------------------------------- Pasos omitibles

it('por omisión NO hay autorización ni preparación: los pasos contestan cómo activarlos', function () {
    surte('200');

    $ulid = solicita('100');

    foreach (['authorize' => 'autorización', 'prepare' => 'preparación'] as $paso => $nombre) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/transfers/{$ulid}/{$paso}")
            ->assertStatus(422)
            ->assertJsonPath('title', fn (string $t): bool => str_contains($t, $nombre));
    }

    // Y enviar procede sin ellos: solicitar → enviar → recibir, tres pasos con un hecho físico cada uno.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();
});

it('con la autorización activada, enviar sin autorizar se rechaza', function () {
    surte('200');

    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForTenant('inventory.transfers_require_authorization', true),
    );

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'autorizar'));

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/authorize")
        ->assertOk()
        ->assertJsonPath('data.status', 'authorized');

    expect($respuesta->json('data.steps.authorized.at'))->not->toBeNull();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();
});

it('con las dos activadas, preparar exige autorización previa', function () {
    surte('200');

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(Settings::class)->setForTenant('inventory.transfers_require_authorization', true);
        app(Settings::class)->setForTenant('inventory.transfers_require_preparation', true);
    });

    $ulid = solicita('100');

    // Preparar mercancía que nadie autorizó a mover es trabajo que se puede tirar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/prepare")
        ->assertStatus(422);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/authorize")
        ->assertOk();

    // Y enviar sin preparar tampoco: el sello es lo que se comprueba, no el estado — al preparar, el estado deja
    // de decir nada de la autorización.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'preparación'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/prepare")
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();
});

// -------------------------------------------------- El almacén de tránsito

it('sólo hay UN almacén de tránsito por negocio', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // Dos repartirían la mercancía en viaje entre dos saldos, y «¿qué traigo en camiones?» tendría dos respuestas.
    expect(Warehouse::query()->where('kind', WarehouseKind::Transit->value)->count())->toBe(1);

    // El índice único es la garantía, no la comprobación del servicio.
    expect(fn () => Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Transit,
        'code' => 'OTRO-TRANSITO',
        'name' => 'Otro tránsito',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('el almacén de tránsito NO admite operaciones manuales', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();

    $transito = almacenDeTransito();

    app(TenantContext::class)->set($this->tenant->id);
    $motivo = WasteReason::create(['name' => 'Se cayó al piso']);
    app(TenantContext::class)->forget();

    // Las cuatro operaciones manuales sobre existencia. Cualquiera de ellas dejaría mercancía sin dueño: lo que hay
    // en tránsito es lo que va en camino, y su saldo tiene que cuadrar con las transferencias abiertas.
    $intentos = [
        ['/api/v1/stock-entries', ['warehouse_ulid' => $transito->ulid, 'article_ulid' => $this->arroz->ulid, 'quantity' => '5']],
        ['/api/v1/stock-exits', ['warehouse_ulid' => $transito->ulid, 'article_ulid' => $this->arroz->ulid, 'quantity' => '5']],
        ['/api/v1/waste', ['warehouse_ulid' => $transito->ulid, 'article_ulid' => $this->arroz->ulid, 'waste_reason_ulid' => $motivo->ulid, 'quantity' => '5']],
        ['/api/v1/stock-counts', ['warehouse_ulid' => $transito->ulid]],
    ];

    foreach ($intentos as [$uri, $cuerpo]) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson($uri, $cuerpo)
            ->assertStatus(422);
    }

    // Y tampoco se puede transferir hacia él ni desde él.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $this->origin->ulid,
            'destination_warehouse_ulid' => $transito->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '5']],
        ])
        ->assertStatus(422);

    expect(saldoEnAlmacen($transito))->toBe('100.0000');
});

it('el motivo de merma del sistema no se renombra ni se da de baja', function () {
    surte('200', '30.0000');

    $ulid = solicita('100');

    foreach ([['ship', '100'], ['receive', '90']] as [$paso, $cantidad]) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/transfers/{$ulid}/{$paso}", [
                'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => $cantidad]],
            ])
            ->assertOk();
    }

    app(TenantContext::class)->set($this->tenant->id);

    $motivo = WasteReason::query()
        ->where('name', ResolveTransferInfrastructure::TRANSIT_DIFFERENCE_REASON)
        ->sole();

    // Si se pudiera renombrar a «se cayó al piso», las pérdidas del camión se agruparían bajo un motivo que
    // significa otra cosa y el reporte que D27 existe para dar quedaría mintiendo.
    // `refresh()` entre intentos, y hace falta: un `update` que lanza deja el atributo escrito EN MEMORIA, así
    // que el siguiente intento arrastraría el cambio anterior y fallaría por el motivo equivocado. Es la clase de
    // detalle que hace que una prueba pase por la razón que no es.
    expect(fn () => $motivo->update(['name' => 'Se cayó al piso']))->toThrow(RuntimeException::class);
    $motivo->refresh();

    expect(fn () => $motivo->update(['status' => 'inactive']))->toThrow(RuntimeException::class);
    $motivo->refresh();

    expect(fn () => $motivo->delete())->toThrow(RuntimeException::class);
    $motivo->refresh();

    // La exigencia de evidencia SÍ se puede cambiar: es política del negocio y no altera lo que el motivo significa.
    $motivo->update(['requires_evidence' => true]);

    expect($motivo->refresh()->requires_evidence)->toBeTrue();

    app(TenantContext::class)->forget();

    // Y por HTTP tampoco. Con 422 y un mensaje que explica, no con un 500: el invariante del modelo es la
    // garantía, pero una excepción de dominio sin mapear sale como error del servidor y parece una falla del
    // sistema en lugar de una regla del negocio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/waste-reasons/{$motivo->ulid}", ['status' => 'inactive'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['status']]);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/waste-reasons/{$motivo->ulid}", ['name' => 'Se cayó al piso'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    // Y la exigencia de evidencia sí, también por HTTP.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/waste-reasons/{$motivo->ulid}", ['requires_evidence' => false])
        ->assertOk()
        ->assertJsonPath('data.requires_evidence', false);
});

// ------------------------------------------------------------------- Folio

it('una transferencia entre dos almacenes centrales se rechaza por el folio', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $centrales = collect(['BODEGA-1', 'BODEGA-2'])->map(fn (string $code): Warehouse => Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Central,
        'code' => $code,
        'name' => "Bodega {$code}",
        'status' => 'active',
    ]));

    app(TenantContext::class)->forget();

    // El folio va por sucursal (§7) y ningún central tiene una. Se rechaza con un mensaje que dice qué hacer, en
    // lugar de inventar una sucursal o dejar el documento sin folio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $centrales[0]->ulid,
            'destination_warehouse_ulid' => $centrales[1]->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '10']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'foliar'));
});

it('desde un central hacia una sucursal el folio sale del destino', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $central = Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Central,
        'code' => 'BODEGA',
        'name' => 'Bodega central',
        'status' => 'active',
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/transfers', [
            'origin_warehouse_ulid' => $central->ulid,
            'destination_warehouse_ulid' => $this->destination->ulid,
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '10']],
        ])
        ->assertCreated()
        ->assertJsonPath('data.folio', 'TR-1');
});

// ------------------------------------------------------------------ Listado

it('lista las transferencias con sus filtros', function () {
    surte('500');

    $abierta = solicita('10');
    $cancelada = solicita('20');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$cancelada}/cancel")
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/transfers')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/transfers?only_open=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $abierta);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/transfers?status=cancelled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $cancelada);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/transfers?destination={$this->destination->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // «Todo lo de este almacén», entre y salga: es la pregunta del encargado, que no piensa en dos listas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/transfers?warehouse={$this->origin->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/transfers?folio=1')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('un conteo de la transferencia se puede ver por su ULID', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/transfers/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.ulid', $ulid)
        ->assertJsonCount(1, 'data.lines');
});

// ------------------------------------------------------------ Autorización

it('recibir exige alcance sobre el DESTINO, no sobre el origen', function () {
    surte('200');

    $ulid = solicita('100');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/ship", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertOk();

    // Una membresía con alcance SÓLO sobre la sucursal de origen no puede recibir: recibe quien tiene la mercancía
    // delante al bajarla del camión. Comprobar siempre el origen dejaría al destino sin poder recibir lo que le
    // mandaron, que es la mitad del flujo.
    app(TenantContext::class)->set($this->tenant->id);

    $membresia = $this->owner->membershipsAcrossTenants()->where('tenant_id', $this->tenant->id)->sole();
    $membresia->update(['has_all_branches' => false]);
    $membresia->branchScopes()->create(['branch_id' => $this->branch->id]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/transfers/{$ulid}/receive", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'quantity' => '100']],
        ])
        ->assertForbidden();
});

it('el mesero no toca las transferencias', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/transfers')
        ->assertForbidden();
});
