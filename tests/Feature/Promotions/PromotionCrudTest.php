<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * ADMINISTRACIÓN DE DEFINICIONES DE PROMOCIÓN (Iteración 6, §6.3)
 *
 * Sólo el catálogo: crear, editar, ver. La APLICACIÓN se prueba aparte, porque vive en otro sitio (el motor + el probe).
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];
    $this->membershipId = $alta['membership']->id;

    app(TenantContext::class)->set($this->tenant->id);
    $this->categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $this->articulo = Article::create([
        'name' => 'Cerveza',
        'category_id' => $this->categoria->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '62.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('crea una promoción de porcentaje por categoría', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/promotions', [
            'name' => 'Happy hour cervezas',
            'type' => 'percentage',
            'percent_value' => '15.00',
            'daily_start' => '18:00',
            'daily_end' => '20:00',
            'weekdays' => [4], // jueves
            'all_branches' => true,
            'targets' => [['category_ulid' => $this->categoria->ulid]],
        ])
        ->assertCreated();

    $respuesta->assertJsonPath('data.type', 'percentage');
    $respuesta->assertJsonPath('data.percent_value', '15.00');
    $respuesta->assertJsonPath('data.weekdays', [4]);
    $respuesta->assertJsonPath('data.version', 1);
    $respuesta->assertJsonCount(1, 'data.targets');
});

it('rechaza un NxM donde se paga tanto o más de lo que se compra', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/promotions', [
            'name' => 'NxM mal',
            'type' => 'nxm',
            'buy_quantity' => 2,
            'pay_quantity' => 2,
            'all_branches' => true,
            'targets' => [['article_ulid' => $this->articulo->ulid]],
        ])
        ->assertStatus(422);
});

it('exige indicar sucursales cuando no aplica a todas', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/promotions', [
            'name' => 'Sin sucursal',
            'type' => 'percentage',
            'percent_value' => '10.00',
            'all_branches' => false,
            'targets' => [['category_ulid' => $this->categoria->ulid]],
        ])
        ->assertStatus(422);
});

it('editar con una versión vieja responde 409', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $promo = Promotion::create([
        'name' => 'Descuento',
        'type' => 'percentage',
        'percent_value' => '10.00',
        'created_by_membership_id' => $this->membershipId,
    ]);
    $promo->targets()->create(['article_category_id' => $this->categoria->id]);
    app(TenantContext::class)->forget();

    // La primera edición pasa y sube la versión a 2.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/promotions/{$promo->ulid}", [
            'name' => 'Descuento renombrado',
            'type' => 'percentage',
            'percent_value' => '12.00',
            'all_branches' => true,
            'targets' => [['category_ulid' => $this->categoria->ulid]],
            'version' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('data.version', 2);

    // La segunda con la versión vieja pierde.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/promotions/{$promo->ulid}", [
            'name' => 'Otro nombre',
            'type' => 'percentage',
            'percent_value' => '20.00',
            'all_branches' => true,
            'targets' => [['category_ulid' => $this->categoria->ulid]],
            'version' => 1,
        ])
        ->assertStatus(409);
});

it('las promociones de un negocio no se ven desde otro', function () {
    app(TenantContext::class)->set($this->tenant->id);
    Promotion::create([
        'name' => 'Sólo de la Fonda',
        'type' => 'percentage',
        'percent_value' => '10.00',
        'created_by_membership_id' => $this->membershipId,
    ]);
    app(TenantContext::class)->forget();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/promotions')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
