<?php

declare(strict_types=1);

use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Topología de almacenes (D11) y el CHECK que la mantiene coherente.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('un almacén central no pertenece a ninguna sucursal', function () {
    $central = Warehouse::factory()->central()->create();

    expect($central->isCentral())->toBeTrue();
    expect($central->branch_id)->toBeNull();
});

it('LA BASE rechaza un almacén central con sucursal', function () {
    // No la aplicación: la base. MySQL 8 aplica los CHECK de verdad, y un almacén
    // central mal marcado surtiría a todas las sucursales sin que nadie lo decidiera.
    expect(fn () => DB::table('warehouses')->insert([
        'ulid' => Warehouse::newUlid(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'kind' => WarehouseKind::Central->value,
        'code' => 'MALO1',
        'name' => 'Central con sucursal',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('LA BASE rechaza un almacén de sucursal sin sucursal', function () {
    expect(fn () => DB::table('warehouses')->insert([
        'ulid' => Warehouse::newUlid(),
        'tenant_id' => $this->tenant->id,
        'branch_id' => null,
        'kind' => WarehouseKind::Branch->value,
        'code' => 'MALO2',
        'name' => 'De sucursal sin sucursal',
        'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('los almacenes alcanzables desde una sucursal incluyen los centrales', function () {
    $propio = Warehouse::factory()->create(['branch_id' => $this->branch->id]);
    $central = Warehouse::factory()->central()->create();

    $otraSucursal = Branch::factory()->create();
    $ajeno = Warehouse::factory()->create(['branch_id' => $otraSucursal->id]);

    $alcanzables = Warehouse::query()
        ->reachableFromBranch($this->branch->id)
        ->pluck('id')
        ->all();

    // Olvidar los centrales es el error natural, y por eso la definición vive en un
    // scope del modelo y no repetida en cada módulo.
    expect($alcanzables)->toContain($propio->id, $central->id);
    expect($alcanzables)->not->toContain($ajeno->id);
});

it('el almacén por defecto de la sucursal es una FK en branches, no una bandera', function () {
    // MySQL no tiene índices únicos parciales, así que "un solo default por sucursal"
    // no se podría imponer desde warehouses. Con la FK aquí es estructural: una
    // columna, luego a lo más un default.
    $almacen = Warehouse::factory()->create(['branch_id' => $this->branch->id]);

    $this->branch->update(['default_warehouse_id' => $almacen->id]);

    expect($this->branch->fresh()->defaultWarehouse->id)->toBe($almacen->id);
});

it('un área de preparación declara de qué almacén descuenta', function () {
    // NOT NULL a propósito: el descuento por receta corre en la cola critical y no
    // debe adivinar. Una suposición en el camino del kardex es una existencia
    // incorrecta.
    $almacen = Warehouse::factory()->create(['branch_id' => $this->branch->id]);

    $area = PreparationArea::factory()->create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'name' => 'Cocina',
    ]);

    expect($area->warehouse->id)->toBe($almacen->id);
});

it('las áreas activas de una sucursal salen en orden de presentación', function () {
    $almacen = Warehouse::factory()->create(['branch_id' => $this->branch->id]);

    PreparationArea::factory()->create([
        'branch_id' => $this->branch->id, 'warehouse_id' => $almacen->id,
        'name' => 'Barra', 'sort_order' => 2,
    ]);
    PreparationArea::factory()->create([
        'branch_id' => $this->branch->id, 'warehouse_id' => $almacen->id,
        'name' => 'Cocina', 'sort_order' => 1,
    ]);
    PreparationArea::factory()->inactive()->create([
        'branch_id' => $this->branch->id, 'warehouse_id' => $almacen->id,
        'name' => 'Parrilla', 'sort_order' => 3,
    ]);

    $nombres = PreparationArea::query()
        ->activeInBranch($this->branch->id)
        ->pluck('name')
        ->all();

    expect($nombres)->toBe(['Cocina', 'Barra']);
});

it('el aislamiento cubre toda la organización', function () {
    Warehouse::factory()->central()->create(['code' => 'CEN-A']);

    $otroTenant = Tenant::factory()->create();

    $ajeno = app(TenantContext::class)->runFor($otroTenant->id, function () {
        $sucursal = Branch::factory()->create();

        return [
            'branch' => $sucursal->id,
            'warehouse' => Warehouse::factory()->create(['branch_id' => $sucursal->id])->id,
        ];
    });

    expect(Branch::query()->find($ajeno['branch']))->toBeNull();
    expect(Warehouse::query()->find($ajeno['warehouse']))->toBeNull();
    expect(Warehouse::query()->central()->count())->toBe(1);
});
