<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Exceptions\StockMovementInvariantException;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL KARDEX Y EL SALDO (§6.2)
 *
 * Lo que estas pruebas cuidan no es el CRUD de una tabla: son las tres reglas que le dan forma al módulo y
 * que, si se rompen, no fallan — se acumulan.
 *
 *   1. **El saldo es un acumulado del kardex.** `balance_after` congelado en cada fila es lo que hace el
 *      kardex legible como estado de cuenta y la proyección auditable.
 *   2. **Las existencias negativas están permitidas.** El POS nunca se bloquea por inventario, así que un
 *      saldo negativo es información y no un estado que haya que impedir.
 *   3. **La dirección es del TIPO.** Una merma no puede sumar existencia, y eso no lo decide el llamador.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Almacén',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Marta',
        ownerPaternalSurname: 'Espinoza',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate saladet',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $this->service = app(RecordStockMovement::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** El saldo de la combinación por omisión de estas pruebas. */
function saldo(): string
{
    return ArticleStock::query()
        ->where('warehouse_id', test()->warehouse->id)
        ->where('article_id', test()->jitomate->id)
        ->value('quantity') ?? '0.0000';
}

it('registra una entrada y deja el saldo en la proyección', function () {
    $movimiento = $this->service->record(
        warehouse: $this->warehouse,
        article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: '12000.0000',
        unitCost: '0.0320',
    );

    expect($movimiento->direction)->toBe(StockMovementDirection::In)
        ->and($movimiento->balance_after)->toBe('12000.0000')
        // El importe congelado: cantidad × costo, a dos decimales porque es un monto.
        ->and($movimiento->total_cost)->toBe('384.00');

    expect(saldo())->toBe('12000.0000');
});

it('el saldo es el acumulado, y cada movimiento congela el suyo', function () {
    // Tres movimientos y la comprobación que importa: el `balance_after` de cada fila es el saldo EN ESE
    // momento, no el actual. Es lo que hace que el kardex se pueda leer como un estado de cuenta.
    $entrada = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '10000.0000',
    );

    $salida = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::ProductionOut, quantity: '900.0000',
    );

    $merma = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::Waste, quantity: '100.0000',
    );

    expect($entrada->balance_after)->toBe('10000.0000')
        ->and($salida->balance_after)->toBe('9100.0000')
        ->and($merma->balance_after)->toBe('9000.0000');

    expect(saldo())->toBe('9000.0000');
});

it('la proyección apunta al último movimiento, que es su testigo', function () {
    $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '500.0000',
    );

    $ultimo = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::Waste, quantity: '50.0000',
    );

    $stock = ArticleStock::query()
        ->where('article_id', $this->jitomate->id)
        ->with('lastMovement')
        ->sole();

    // Si la proyección se desviara, esta comparación lo detecta sin recorrer el kardex. Es la razón de que
    // la columna exista.
    expect($stock->last_movement_id)->toBe($ultimo->id)
        ->and($stock->quantity)->toBe($stock->lastMovement->balance_after);
});

it('PERMITE existencias negativas: el POS nunca se bloquea por inventario', function () {
    // §6.2. No es una concesión: la causa más común de un negativo no es un error de captura, es que el
    // conteo va atrasado. Impedirlo obligaría a que el sistema detuviera una venta que ya ocurrió.
    $movimiento = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::SaleConsumption, quantity: '250.0000',
    );

    expect($movimiento->balance_after)->toBe('-250.0000');

    $stock = ArticleStock::query()->where('article_id', $this->jitomate->id)->sole();

    expect($stock->isNegative())->toBeTrue()
        ->and(ArticleStock::query()->negative()->count())->toBe(1);
});

it('la dirección la decide el TIPO, no el llamador', function () {
    // Una merma que suma existencia no fallaría en ningún sitio: se acumularía en el saldo y en el reporte
    // de mermas del mes con signo contrario. Por eso se rechaza en lugar de ignorarse.
    expect(fn () => $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::Waste, quantity: '10.0000',
        direction: StockMovementDirection::In,
    ))->toThrow(StockMovementInvariantException::class);

    // Y pasar la dirección que el tipo ya tiene es válido: no es una contradicción, es redundancia.
    $movimiento = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::Waste, quantity: '10.0000',
        direction: StockMovementDirection::Out,
    );

    expect($movimiento->direction)->toBe(StockMovementDirection::Out);
});

it('un ajuste EXIGE dirección explícita, porque ahí el signo es la información', function () {
    expect(fn () => $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::ManualAdjustment, quantity: '5.0000',
    ))->toThrow(StockMovementInvariantException::class);

    // Con dirección, las dos funcionan: un ajuste puede sumar o restar por naturaleza.
    foreach ([StockMovementDirection::In, StockMovementDirection::Out] as $direccion) {
        $movimiento = $this->service->record(
            warehouse: $this->warehouse, article: $this->jitomate,
            kind: StockMovementKind::ManualAdjustment, quantity: '5.0000',
            direction: $direccion,
        );

        expect($movimiento->direction)->toBe($direccion);
    }

    // Sumó 5 y restó 5.
    expect(saldo())->toBe('0.0000');
});

it('rechaza una cantidad de cero o negativa', function () {
    // La cantidad SIEMPRE es positiva: la dirección viaja aparte, y una cantidad con signo haría que un
    // `SUM` descuidado devolviera un número plausible y equivocado.
    foreach (['0.0000', '-5.0000'] as $cantidad) {
        expect(fn () => $this->service->record(
            warehouse: $this->warehouse, article: $this->jitomate,
            kind: StockMovementKind::PurchaseReceipt, quantity: $cantidad,
        ))->toThrow(StockMovementInvariantException::class);
    }

    expect(StockMovement::query()->count())->toBe(0);
});

it('un lote de otro artículo se rechaza aunque exista', function () {
    $gramo = Unit::query()->where('code', 'g')->firstOrFail();
    $queso = Article::create(['name' => 'Queso', 'base_unit_id' => $gramo->id, 'is_supply' => true]);

    $loteAjeno = ArticleLot::create([
        'article_id' => $queso->id,
        'code' => 'L-2026-A',
        'received_at' => now()->toDateString(),
    ]);

    // La FK está satisfecha —el lote existe— así que sin esta validación el movimiento pasaría y mezclaría
    // dos existencias distintas bajo el mismo saldo.
    expect(fn () => $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '100.0000',
        lot: $loteAjeno,
    ))->toThrow(StockMovementInvariantException::class);
});

it('el mismo artículo con y sin lote lleva saldos SEPARADOS', function () {
    $lote = ArticleLot::create([
        'article_id' => $this->jitomate->id,
        'code' => 'L-2026-B',
        'received_at' => now()->toDateString(),
    ]);

    $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '100.0000',
    );

    $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '300.0000',
        lot: $lote,
    );

    // Dos filas de saldo, no una de 400: el lote es parte de la identidad del saldo. Es lo que permite que
    // FEFO sepa de qué lote está sacando.
    expect(ArticleStock::query()->where('article_id', $this->jitomate->id)->count())->toBe(2)
        ->and(saldo())->toBe('100.0000');
});

it('congela el documento origen con su ULID público', function () {
    // La lección de D151 aplicada desde el día uno: la llave interna deja de significar algo si el documento
    // desaparece, y no se puede exponer por la API.
    $movimiento = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::PurchaseReceipt, quantity: '100.0000',
        source: $this->jitomate,
    );

    expect($movimiento->source_type)->toBe(Article::class)
        ->and($movimiento->source_id)->toBe($this->jitomate->id)
        ->and($movimiento->source_ulid)->toBe($this->jitomate->ulid);
});

it('re-registrar con la misma llave de idempotencia NO duplica el movimiento', function () {
    // El descuento por venta es asíncrono (§6.2), así que un job re-despachado tiene que ser inofensivo.
    // El índice único lo hace imposible aunque el código se equivoque.
    $primero = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::SaleConsumption, quantity: '50.0000',
        idempotencyKey: 'sale:01ABC:consumption',
    );

    $segundo = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::SaleConsumption, quantity: '50.0000',
        idempotencyKey: 'sale:01ABC:consumption',
    );

    expect($segundo->id)->toBe($primero->id)
        ->and(StockMovement::query()->count())->toBe(1)
        // Y el saldo se movió UNA vez, que es lo que de verdad importa.
        ->and(saldo())->toBe('-50.0000');
});

it('sin llave de idempotencia, dos movimientos iguales son dos movimientos', function () {
    // No es lo mismo que el caso anterior: dos recepciones idénticas del mismo insumo el mismo día son
    // perfectamente normales, y deduplicarlas por parecido perdería una de las dos.
    foreach ([1, 2] as $vez) {
        $this->service->record(
            warehouse: $this->warehouse, article: $this->jitomate,
            kind: StockMovementKind::PurchaseReceipt, quantity: '100.0000',
        );
    }

    expect(StockMovement::query()->count())->toBe(2)
        ->and(saldo())->toBe('200.0000');
});

it('el kardex se lee del movimiento más reciente hacia atrás', function () {
    foreach (['100.0000', '200.0000', '300.0000'] as $cantidad) {
        $this->service->record(
            warehouse: $this->warehouse, article: $this->jitomate,
            kind: StockMovementKind::PurchaseReceipt, quantity: $cantidad,
        );
    }

    $kardex = StockMovement::query()
        ->kardex($this->warehouse->id, $this->jitomate->id)
        ->get();

    // Los tres se registran en el mismo segundo, así que sin el desempate por `id` el orden sería el que
    // MySQL quisiera y el saldo de la columna derecha parecería ir hacia atrás.
    expect($kardex->pluck('quantity')->all())->toBe(['300.0000', '200.0000', '100.0000'])
        ->and($kardex->pluck('balance_after')->all())->toBe(['600.0000', '300.0000', '100.0000']);
});

it('un movimiento sin costo conocido no inventa uno', function () {
    // Un ajuste de un artículo sin costo capturado no puede tener importe, y un cero diría que la mercancía
    // es gratis — de ahí saldría un valor de inventario falso.
    $movimiento = $this->service->record(
        warehouse: $this->warehouse, article: $this->jitomate,
        kind: StockMovementKind::ManualAdjustment, quantity: '10.0000',
        direction: StockMovementDirection::In,
    );

    expect($movimiento->unit_cost)->toBeNull()
        ->and($movimiento->total_cost)->toBeNull();
});
