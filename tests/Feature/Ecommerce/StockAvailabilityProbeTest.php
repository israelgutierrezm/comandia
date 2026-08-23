<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Inventory\NullStockAvailabilityProbe;
use App\Modules\Shared\Domain\Contracts\StockAvailabilityProbe;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * SONDA DE EXISTENCIA (Iteración 8, Tanda B): «¿hay existencia en la sucursal?».
 *
 * La tienda respeta stock (ADR-007) preguntando por esta sonda del kernel. `Inventory` la implementa sumando existencias
 * sobre los almacenes de la sucursal; el null-object devuelve `true` para no ocultar el catálogo por un fallo.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda', ownerEmail: 'ana@fonda.mx', ownerFirstName: 'Ana', ownerPaternalSurname: 'Gómez', plainPassword: 'secreto-largo-123',
    );
    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el kernel enlaza la implementación real de Inventory', function () {
    expect(app(StockAvailabilityProbe::class))
        ->toBeInstanceOf(\App\Modules\Inventory\Application\InventoryStockAvailabilityProbe::class);
});

it('reporta existencia según la proyección del almacén de la sucursal', function () {
    [$articleId, $branchId] = app(TenantContext::class)->runFor($this->tenant->id, function () {
        $warehouse = Warehouse::factory()->create(['branch_id' => $this->branch->id]);
        $article = Article::create([
            'name' => 'Cerveza',
            'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true,
            'base_price' => '60.00',
            'is_inventoriable' => true,
        ]);

        $probe = app(StockAvailabilityProbe::class);

        // Sin existencia: falso.
        expect($probe->hasStock($article->id, $this->branch->id))->toBeFalse();

        ArticleStock::create(['warehouse_id' => $warehouse->id, 'article_id' => $article->id, 'quantity' => '5.0000']);

        // Con existencia positiva: verdadero.
        expect($probe->hasStock($article->id, $this->branch->id))->toBeTrue();

        return [$article->id, $this->branch->id];
    });

    expect($articleId)->toBeInt();
    expect($branchId)->toBeInt();
});

it('el null-object siempre reporta existencia', function () {
    expect((new NullStockAvailabilityProbe())->hasStock(1, 1))->toBeTrue();
});
