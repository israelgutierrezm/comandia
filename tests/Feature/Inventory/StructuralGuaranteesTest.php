<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\OpenStockCount;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\ProductionOrderStatus;
use App\Modules\Inventory\Domain\Enums\StockCountStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Domain\Enums\PurchaseReceiptStatus;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * LAS GARANTÍAS QUE IMPONE LA BASE, PROBADAS SIN PASAR POR LA APLICACIÓN
 *
 * La Iteración 3 apoyó siete invariantes en restricciones reales de MySQL en lugar de en comprobaciones de código. Todas
 * tienen su prueba «por la puerta»: el endpoint devuelve 422 con un mensaje útil. Pero **esas pruebas no comprueban la
 * garantía** — comprueban la cortesía.
 *
 * La diferencia importa porque una comprobación de aplicación tiene dos agujeros que un índice no tiene:
 *
 *   1. **La carrera.** Entre leer «¿ya hay un conteo abierto?» y escribir cabe otra petición. La comprobación no puede
 *      cerrar esa ventana; el índice no la tiene.
 *   2. **El segundo camino.** Un seeder, una migración de datos, un job futuro escrito de prisa, un comando de consola.
 *      Ninguno pasa por el Form Request.
 *
 * Así que estas pruebas escriben **directo por el modelo**, saltándose el servicio y su comprobación, y afirman que la
 * base rechaza. Es el mismo argumento con el que la Iteración 2 defendió la idempotencia del costo —«el índice único lo
 * hace imposible aunque el código se equivoque»— y que D212 tuvo cuidado de no perder al cambiar aquel servicio.
 *
 * Y cada garantía viene en pareja con su **contraparte**: que lo que debe caber, cabe. Un índice demasiado estricto no
 * falla en pruebas de rechazo — falla rechazando operaciones legítimas, que es más difícil de ver.
 */

/**
 * Crea una recepción **sin pasar por el servicio**, que es todo el punto de este archivo.
 *
 * Nombre propio y largo a propósito: los ayudantes de prueba son funciones globales y el candado de D191 rechaza los
 * nombres que otro archivo querría usar.
 */
function recepcionCruda(
    Supplier $supplier,
    int $warehouseId,
    int $branchId,
    int $membershipId,
    PurchaseReceiptStatus $status,
    int $folio,
    ?string $document = null,
    ?int $reverses = null,
): PurchaseReceipt {
    return PurchaseReceipt::create([
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouseId,
        'status' => $status,
        'folio_branch_id' => $branchId,
        'series' => 'RC',
        'folio' => $folio,
        'supplier_document_number' => $document,
        'received_at' => CarbonImmutable::now(),
        'reverses_receipt_id' => $reverses,
        'created_by_membership_id' => $membershipId,
    ]);
}

beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con garantías',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Elena',
        ownerPaternalSurname: 'Navarro',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->membership = $this->owner->membershipsAcrossTenants()
        ->where('tenant_id', $this->tenant->id)
        ->sole();

    // Contexto de petición: varios servicios lo exigen para poder decir quién hizo la operación.
    app(ContextHolder::class)->set(RequestContext::forMember(
        tenant: $this->tenant,
        user: $this->owner,
        membership: $this->membership,
        activeRole: null,
        activeBranch: $this->branch,
    ));

    $this->article = Article::create([
        'name' => 'Arroz',
        'base_unit_id' => Unit::query()->where('code', 'kg')->value('id'),
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);
});

afterEach(function () {
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

it('UN conteo abierto por almacén: lo impone el índice, no el servicio', function () {
    app(RecordStockMovement::class)->record(
        warehouse: $this->warehouse,
        article: $this->article,
        kind: StockMovementKind::ManualEntry,
        quantity: '40',
    );

    app(OpenStockCount::class)->open($this->warehouse);

    // Sin pasar por `OpenStockCount`, que comprueba antes. La columna generada `open_warehouse_key` vale el almacén
    // mientras el estado es `counting`, y el índice único la rechaza (D176).
    expect(fn () => StockCount::create([
        'warehouse_id' => $this->warehouse->id,
        'status' => StockCountStatus::Counting,
        'started_by_membership_id' => $this->membership->id,
        'started_at' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class);

    expect(StockCount::query()->count())->toBe(1);
});

it('un almacén puede tener MUCHOS conteos cerrados y sólo uno abierto', function () {
    // La contraparte, y la que un índice mal escrito rompería: si la columna generada valiera siempre el almacén en
    // lugar de sólo mientras está en captura, el segundo conteo de la historia sería imposible.
    foreach (range(1, 3) as $ignored) {
        StockCount::create([
            'warehouse_id' => $this->warehouse->id,
            'status' => StockCountStatus::Cancelled,
            'started_by_membership_id' => $this->membership->id,
            'started_at' => CarbonImmutable::now(),
        ]);
    }

    StockCount::create([
        'warehouse_id' => $this->warehouse->id,
        'status' => StockCountStatus::Counting,
        'started_by_membership_id' => $this->membership->id,
        'started_at' => CarbonImmutable::now(),
    ]);

    expect(StockCount::query()->count())->toBe(4);
});

it('UN almacén de tránsito por negocio: lo impone el índice', function () {
    $crear = fn (string $code): Warehouse => Warehouse::create([
        'branch_id' => null,
        'kind' => WarehouseKind::Transit,
        'code' => $code,
        'name' => 'Tránsito',
        'status' => 'active',
    ]);

    $crear('TRANSITO-1');

    // Dos repartirían la mercancía en viaje entre dos saldos, y «¿qué traigo en camiones?» tendría dos respuestas
    // (D184). Un código distinto no ayuda: la unicidad es por negocio y tipo, no por código.
    expect(fn () => $crear('TRANSITO-2'))->toThrow(QueryException::class);
});

it('UNA reversa por recepción: lo impone el índice', function () {
    $supplier = Supplier::create(['code' => 'PROV', 'legal_name' => 'Proveedor']);

    $original = recepcionCruda(
        $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
        PurchaseReceiptStatus::Confirmed, 1,
    );

    recepcionCruda(
        $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
        PurchaseReceiptStatus::Confirmed, 2, reverses: $original->id,
    );

    // Reversarla otra vez sacaría del inventario mercancía que ya salió (D210). El servicio lo comprueba; el índice lo
    // garantiza incluso si dos peticiones simultáneas pasan la comprobación a la vez.
    expect(fn () => recepcionCruda(
        $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
        PurchaseReceiptStatus::Confirmed, 3, reverses: $original->id,
    ))->toThrow(QueryException::class);
});

it('muchas recepciones SIN reversar no se estorban', function () {
    // La contraparte. El `NULL` no deduplica en MySQL, y aquí eso es la característica: la mayoría de las recepciones no
    // reversan nada (misma lógica que el RFC del proveedor, D200). Un índice que las hiciera colisionar dejaría al
    // negocio con una sola recepción en toda su historia.
    $supplier = Supplier::create(['code' => 'PROV', 'legal_name' => 'Proveedor']);

    foreach (range(1, 4) as $folio) {
        recepcionCruda(
            $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
            PurchaseReceiptStatus::Confirmed, $folio,
        );
    }

    expect(PurchaseReceipt::query()->count())->toBe(4);
});

it('la MISMA factura del mismo proveedor no se captura dos veces: lo impone el índice', function () {
    $supplier = Supplier::create(['code' => 'PROV', 'legal_name' => 'Proveedor']);

    recepcionCruda(
        $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
        PurchaseReceiptStatus::Draft, 1, document: 'A-777',
    );

    // Es el error de captura más caro de todos: duplica existencia, duplica costo y descuadra el inventario sin que nada
    // avise (D213). El Form Request da el mensaje; esto es la garantía.
    expect(fn () => recepcionCruda(
        $supplier, $this->warehouse->id, $this->branch->id, $this->membership->id,
        PurchaseReceiptStatus::Draft, 2, document: 'A-777',
    ))->toThrow(QueryException::class);
});

it('la misma factura de OTRO proveedor sí se puede capturar', function () {
    // La contraparte. Dos proveedores numeran sus facturas por su cuenta, así que la coincidencia es normal — un índice
    // sin el proveedor rechazaría compras legítimas, y es el error que se comete al «endurecer» la regla sin pensar.
    $uno = Supplier::create(['code' => 'PROV-1', 'legal_name' => 'Proveedor uno']);
    $otro = Supplier::create(['code' => 'PROV-2', 'legal_name' => 'Proveedor dos']);

    foreach ([[$uno, 1], [$otro, 2]] as [$proveedor, $folio]) {
        recepcionCruda(
            $proveedor, $this->warehouse->id, $this->branch->id, $this->membership->id,
            PurchaseReceiptStatus::Draft, $folio, document: 'A-777',
        );
    }

    expect(PurchaseReceipt::query()->count())->toBe(2);
});

it('el kardex rechaza una dirección que no corresponde al tipo: lo impone un CHECK', function () {
    // Una merma que SUMA existencia, o una devolución que entra. El servicio no lo permite —la dirección la decide el
    // tipo— y el CHECK lo hace imposible por cualquier camino. Sin él, un job mal escrito podría meter una merma que
    // aumenta el inventario, y el reporte de pérdidas diría que el negocio gana mercancía al tirarla.
    $movimientoImposible = fn (StockMovementKind $kind): callable => fn () => StockMovement::create([
        'warehouse_id' => $this->warehouse->id,
        'article_id' => $this->article->id,
        'kind' => $kind,
        'direction' => StockMovementDirection::In,
        'quantity' => '10',
        'balance_after' => '10',
        'occurred_at' => CarbonImmutable::now(),
    ]);

    expect($movimientoImposible(StockMovementKind::Waste))->toThrow(QueryException::class);

    // Y la devolución a proveedor, que el paso 9 agregó al enum: sólo sale.
    expect($movimientoImposible(StockMovementKind::PurchaseReturn))->toThrow(QueryException::class);
});

it('el ajuste SÍ admite las dos direcciones: ahí el signo es la información', function () {
    // La contraparte, y la que un CHECK escrito de más rompería. Un ajuste que sólo pudiera restar dejaría sin registrar
    // el descuadre a favor —el sistema decía 8 y hay 10— que es igual de real y ocurre igual de seguido.
    foreach ([StockMovementDirection::In, StockMovementDirection::Out] as $direction) {
        app(RecordStockMovement::class)->record(
            warehouse: $this->warehouse,
            article: $this->article,
            kind: StockMovementKind::ManualAdjustment,
            quantity: '5',
            direction: $direction,
            notes: 'Descuadre sin explicación',
        );
    }

    expect(StockMovement::query()->where('kind', StockMovementKind::ManualAdjustment->value)->count())->toBe(2);
});

it('una transferencia a sí misma no existe: lo impone un CHECK', function () {
    // El Form Request da el mensaje; esto es la garantía. Una transferencia a sí misma escribiría dos movimientos que se
    // anulan y un documento que no significa nada — mejor que no exista.
    expect(fn () => Transfer::create([
        'origin_warehouse_id' => $this->warehouse->id,
        'destination_warehouse_id' => $this->warehouse->id,
        'status' => TransferStatus::Requested,
        'folio_branch_id' => $this->branch->id,
        'series' => 'TR',
        'folio' => 99,
        'requested_by_membership_id' => $this->membership->id,
        'requested_at' => CarbonImmutable::now(),
    ]))->toThrow(QueryException::class);
});

it('una orden de producción de cero no existe: lo impone un CHECK', function () {
    expect(fn () => ProductionOrder::create([
        'warehouse_id' => $this->warehouse->id,
        'article_id' => $this->article->id,
        'status' => ProductionOrderStatus::Draft,
        'planned_quantity' => '0',
        'created_by_membership_id' => $this->membership->id,
    ]))->toThrow(QueryException::class);
});
