<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TIENDA EN LÍNEA — ADMINISTRACIÓN (Iteración 8, Tanda B)
 *
 * Configurar la tienda (datos públicos + sucursales que atiende) y los ajustes de tienda por artículo (política de stock,
 * visibilidad, SEO, precio por canal). TODO gateado por `module:Ecommerce` (sin el módulo, 403) y `ecommerce.store.configure`.
 * El slug es único globalmente porque `/t/{slug}` resuelve el negocio sin sesión.
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

    app(TenantContext::class)->set($this->tenant->id);
    $this->article = Article::create([
        'name' => 'Enchiladas',
        'category_id' => ArticleCategory::create(['name' => 'Fuertes', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '145.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

function enableStore(int $tenantId): void
{
    app(TenantContext::class)->runFor($tenantId, fn () => app(ManageTenantModules::class)->set('Ecommerce', true));
}

it('sin el módulo activo, la configuración de la tienda se rechaza (403)', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/store')
        ->assertStatus(403);
});

it('configura la tienda con sus sucursales', function () {
    enableStore($this->tenant->id);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/store', [
            'slug' => 'fonda-centro',
            'name' => 'Fonda del Centro',
            'is_active' => true,
            'theme_primary' => '#c2410c',
            'branch_ulids' => [$this->branch->ulid],
        ])
        ->assertOk()
        ->assertJsonPath('data.slug', 'fonda-centro')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.public_url', fn ($u) => str_ends_with((string) $u, '/t/fonda-centro'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/store')
        ->assertOk()
        ->assertJsonPath('data.slug', 'fonda-centro')
        ->assertJsonPath('data.branch_ulids', [$this->branch->ulid]);
});

it('el slug de la tienda es único entre todos los negocios', function () {
    enableStore($this->tenant->id);
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/store', ['slug' => 'la-tienda', 'name' => 'A', 'is_active' => true, 'theme_primary' => '#000000', 'branch_ulids' => []])
        ->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    enableStore($otro['tenant']->id);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->putJson('/api/v1/store', ['slug' => 'la-tienda', 'name' => 'B', 'is_active' => true, 'theme_primary' => '#000000', 'branch_ulids' => []])
        ->assertStatus(422);
});

it('edita los ajustes de tienda de un artículo', function () {
    enableStore($this->tenant->id);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->article->ulid}/store-settings", [
            'stock_policy' => 'hide',
            'is_in_store' => true,
            'seo_title' => 'Enchiladas suizas caseras',
            'seo_description' => null,
            'channel_price' => '160.00',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_policy', 'hide')
        ->assertJsonPath('data.is_in_store', true)
        ->assertJsonPath('data.channel_price', '160.00');
});

it('rechaza una política de stock inválida', function () {
    enableStore($this->tenant->id);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->article->ulid}/store-settings", [
            'stock_policy' => 'inventada', 'is_in_store' => true,
        ])
        ->assertStatus(422);
});

it('un negocio no ve la tienda de otro', function () {
    enableStore($this->tenant->id);
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/store', ['slug' => 'centro', 'name' => 'A', 'is_active' => true, 'theme_primary' => '#000000', 'branch_ulids' => []])
        ->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte', ownerEmail: 'beto@cafe.mx', ownerFirstName: 'Beto', ownerPaternalSurname: 'Luna', plainPassword: 'secreto-largo-123',
    );
    enableStore($otro['tenant']->id);
    app(TenantContext::class)->forget();

    // El negocio B no tiene tienda todavía: su GET devuelve null, no la de A.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/store')
        ->assertOk()
        ->assertJsonPath('data', null);
});
