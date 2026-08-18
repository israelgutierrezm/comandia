<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\IssueStock;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * FEFO — *First Expired, First Out* (D23, §6.2)
 *
 * §6.2 lo pide **automático y sin selección manual obligatoria**, y esa palabra da forma a todo: quien registra
 * una salida dice cuánto sale, no de dónde.
 *
 * Lo que estas pruebas cuidan, en orden de importancia:
 *
 *   1. **Los que NO caducan salen al final.** Es lo que no se adivina: en MySQL —y en PHP— los `null` ordenan
 *      primero, así que un ordenamiento ingenuo sacaría la sal antes que la leche del jueves.
 *   2. **Una salida se PARTE** cuando el primer lote no alcanza, y cada renglón dice de qué partida salió.
 *   3. **El faltante va SIN LOTE**, no al último lote usado: un lote en negativo ordenaría primero y absorbería
 *      todas las salidas siguientes, volviendo el error permanente.
 *   4. **Un lote explícito gana a FEFO**, porque quien lo indica está mirando la caja física.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Lotes',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Rebeca',
        ownerPaternalSurname: 'Guerra',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $ml = Unit::query()->where('code', 'ml')->firstOrFail();

    $this->leche = Article::create([
        'name' => 'Leche entera',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    $this->issues = app(IssueStock::class);
    $this->records = app(RecordStockMovement::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Crea un lote con existencia. `null` en la caducidad = no caduca. */
function loteCon(string $code, ?string $expiresAt, string $quantity): ArticleLot
{
    $lot = ArticleLot::create([
        'article_id' => test()->leche->id,
        'code' => $code,
        'expires_at' => $expiresAt,
        'received_at' => now()->subDays(10)->toDateString(),
    ]);

    test()->records->record(
        warehouse: test()->warehouse,
        article: test()->leche,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: $quantity,
        lot: $lot,
    );

    return $lot;
}

/** El saldo de un lote, o el de «sin lote» si se pasa `null`. */
function saldoDe(?ArticleLot $lot): string
{
    return ArticleStock::query()
        ->where('warehouse_id', test()->warehouse->id)
        ->where('article_id', test()->leche->id)
        ->where(fn ($q) => $lot === null ? $q->whereNull('lot_id') : $q->where('lot_id', $lot->id))
        ->value('quantity') ?? '0.0000';
}

it('saca primero el lote que caduca antes', function () {
    $abril = loteCon('L-ABR', now()->addMonths(2)->toDateString(), '1000.0000');
    $marzo = loteCon('L-MAR', now()->addMonth()->toDateString(), '1000.0000');

    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '400.0000',
    );

    // Un solo movimiento, del lote de marzo: el de abril no se toca aunque se haya recibido primero.
    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->lot_id)->toBe($marzo->id);

    expect(saldoDe($marzo))->toBe('600.0000')
        ->and(saldoDe($abril))->toBe('1000.0000');
});

it('los lotes que NO caducan salen AL FINAL', function () {
    // El caso que rompe cualquier ordenamiento ingenuo: `null` ordena primero en SQL y compara como menor en
    // PHP, así que la sal saldría antes que la leche del jueves.
    $sinCaducidad = loteCon('L-SIN', null, '1000.0000');
    $conCaducidad = loteCon('L-CON', now()->addYear()->toDateString(), '1000.0000');

    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '300.0000',
    );

    expect($movimientos[0]->lot_id)->toBe(
        $conCaducidad->id,
        'Salió el lote sin caducidad antes que uno que caduca: FEFO invertido.'
    );

    expect(saldoDe($sinCaducidad))->toBe('1000.0000');
});

it('PARTE la salida en varios movimientos cuando un lote no alcanza', function () {
    $marzo = loteCon('L-MAR', now()->addMonth()->toDateString(), '300.0000');
    $abril = loteCon('L-ABR', now()->addMonths(2)->toDateString(), '500.0000');
    $mayo = loteCon('L-MAY', now()->addMonths(3)->toDateString(), '500.0000');

    // 600 no caben en marzo (300): salen 300 de marzo y 300 de abril. Mayo no se toca.
    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '600.0000',
    );

    expect($movimientos)->toHaveCount(2);

    expect([$movimientos[0]->lot_id, $movimientos[0]->quantity])->toBe([$marzo->id, '300.0000'])
        ->and([$movimientos[1]->lot_id, $movimientos[1]->quantity])->toBe([$abril->id, '300.0000']);

    expect(saldoDe($marzo))->toBe('0.0000')
        ->and(saldoDe($abril))->toBe('200.0000')
        ->and(saldoDe($mayo))->toBe('500.0000');
});

it('el faltante va SIN LOTE y no deja ningún lote en negativo', function () {
    $marzo = loteCon('L-MAR', now()->addMonth()->toDateString(), '200.0000');

    // Salen 500 y sólo hay 200: el resto no tiene lote al que cargarse.
    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '500.0000',
    );

    expect($movimientos)->toHaveCount(2)
        ->and($movimientos[0]->lot_id)->toBe($marzo->id)
        ->and($movimientos[1]->lot_id)->toBeNull();

    // El lote queda en CERO, nunca en negativo. Si quedara negativo ordenaría primero en FEFO y absorbería
    // todas las salidas siguientes: el error se volvería permanente.
    expect(saldoDe($marzo))->toBe('0.0000')
        ->and(saldoDe(null))->toBe('-300.0000');
});

it('no salta lotes AGOTADOS ni CADUCADOS', function () {
    $agotado = loteCon('L-AGO', now()->addDays(5)->toDateString(), '100.0000');
    $caducado = loteCon('L-CAD', now()->addDays(10)->toDateString(), '100.0000');
    $bueno = loteCon('L-OK', now()->addMonths(6)->toDateString(), '500.0000');

    // El primero se vacía por completo, el segundo se marca caducado a mano.
    $this->issues->issue(
        warehouse: $this->warehouse, article: $this->leche,
        kind: StockMovementKind::ManualExit, quantity: '100.0000',
    );

    $caducado->update(['status' => LotStatus::Expired]);

    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '200.0000',
    );

    // Salta el agotado —no tiene saldo— y el caducado —no puede surtir— y va al bueno.
    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->lot_id)->toBe($bueno->id);

    // El caducado conserva su existencia: hasta que alguien registre la merma, la mercancía sigue ahí. Darla
    // por perdida sola convertiría un vencimiento de calendario en una pérdida que nadie revisó.
    expect(saldoDe($caducado))->toBe('100.0000');
});

it('un lote explícito GANA a FEFO', function () {
    $marzo = loteCon('L-MAR', now()->addMonth()->toDateString(), '500.0000');
    $mayo = loteCon('L-MAY', now()->addMonths(3)->toDateString(), '500.0000');

    // Quien indica el lote está mirando la caja física: el sistema no tiene mejor información que eso.
    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::ManualExit,
        quantity: '100.0000',
        lot: $mayo,
    );

    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->lot_id)->toBe($mayo->id);

    expect(saldoDe($marzo))->toBe('500.0000');
});

it('un artículo SIN lotes sale en un solo movimiento y sin lote', function () {
    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $jitomate = Article::create([
        'name' => 'Jitomate',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        // Sin lotes: es el caso de la mayoría del catálogo.
    ]);

    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $jitomate,
        kind: StockMovementKind::ManualExit,
        quantity: '750.0000',
    );

    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->lot_id)->toBeNull()
        ->and($movimientos[0]->quantity)->toBe('750.0000');
});

it('la llave de idempotencia se sufija por movimiento', function () {
    loteCon('L-MAR', now()->addMonth()->toDateString(), '300.0000');
    loteCon('L-ABR', now()->addMonths(2)->toDateString(), '300.0000');

    // Sin sufijo, el segundo movimiento chocaría con el primero y se descartaría en silencio: la salida
    // quedaría a medias y el saldo mal, sin que nada fallara.
    $movimientos = $this->issues->issue(
        warehouse: $this->warehouse,
        article: $this->leche,
        kind: StockMovementKind::SaleConsumption,
        quantity: '500.0000',
        idempotencyKey: 'venta:01ABC:consumo',
    );

    expect($movimientos)->toHaveCount(2);

    expect(array_map(fn ($m) => $m->idempotency_key, $movimientos))
        ->toBe(['venta:01ABC:consumo:0', 'venta:01ABC:consumo:1']);
});

it('repetir la salida con la misma llave no vuelve a mover el saldo', function () {
    loteCon('L-MAR', now()->addMonth()->toDateString(), '300.0000');
    loteCon('L-ABR', now()->addMonths(2)->toDateString(), '300.0000');

    foreach ([1, 2] as $intento) {
        $this->issues->issue(
            warehouse: $this->warehouse,
            article: $this->leche,
            kind: StockMovementKind::SaleConsumption,
            quantity: '500.0000',
            idempotencyKey: 'venta:01XYZ:consumo',
        );
    }

    // El total sigue siendo 600 - 500 = 100, no 600 - 1000. Es la garantía que hace inofensivo re-despachar
    // el job de descuento por venta.
    $total = ArticleStock::query()
        ->where('article_id', $this->leche->id)
        ->get()
        ->reduce(fn (string $suma, ArticleStock $s): string => bcadd($suma, $s->quantity, 4), '0.0000');

    expect($total)->toBe('100.0000');
});
