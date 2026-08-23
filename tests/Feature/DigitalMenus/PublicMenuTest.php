<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use App\Modules\Publishing\Infrastructure\Models\ArticlePublication;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MENÚ PÚBLICO POR QR (Iteración 8, Tanda A): `/m/{slug}`, sin autenticación.
 *
 * El slug —único globalmente— resuelve el negocio (no la sesión). Sirve los artículos vendibles y disponibles de la
 * sucursal, con su publicación. Un menú inactivo o de un negocio que apagó el módulo no existe para el público (404).
 */
beforeEach(function () {
    $this->withoutVite(); // el shell rinde @vite; en pruebas no dependemos del build

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        app(ManageTenantModules::class)->set('DigitalMenus', true);

        $article = Article::create([
            'name' => 'Enchiladas suizas',
            'category_id' => ArticleCategory::create(['name' => 'Platos fuertes', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true,
            'base_price' => '145.00',
            'is_available_in_pos' => true,
        ]);
        ArticlePublication::create([
            'article_id' => $article->id,
            'long_description' => 'Bañadas en salsa verde y gratinadas.',
            'is_visible' => true,
        ]);

        $this->menu = DigitalMenu::create([
            'branch_id' => $this->branch->id,
            'slug' => 'fonda-centro',
            'is_active' => true,
            'show_prices' => true,
        ]);
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('sirve el menú público con sus platillos y precios', function () {
    $this->get('/m/fonda-centro')
        ->assertOk()
        ->assertSee($this->branch->name) // nombre de la sucursal en el título y el encabezado
        ->assertSee('Enchiladas suizas')
        ->assertSee('salsa verde y gratinadas') // tramo sin acentos: el blob JSON escapa los acentos a \u00XX
        ->assertSee('145.00');
});

it('un slug inexistente da 404', function () {
    $this->get('/m/no-existe')->assertNotFound();
});

it('un menú inactivo no se sirve', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->menu->update(['is_active' => false]));

    $this->get('/m/fonda-centro')->assertNotFound();
});

it('si el negocio apaga el módulo, el menú deja de servirse', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(ManageTenantModules::class)->set('DigitalMenus', false));

    $this->get('/m/fonda-centro')->assertNotFound();
});

it('oculta los precios cuando el menú no los muestra', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->menu->update(['show_prices' => false]));

    $this->get('/m/fonda-centro')
        ->assertOk()
        ->assertSee('Enchiladas suizas')
        ->assertDontSee('145.00');
});

it('el slug de un negocio no filtra los artículos de otro', function () {
    // Otro negocio con su propio menú y su propio platillo.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function () use ($otro): void {
        app(ManageTenantModules::class)->set('DigitalMenus', true);
        Article::create([
            'name' => 'Croissant secreto',
            'category_id' => ArticleCategory::create(['name' => 'Panadería', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
            'is_sellable' => true,
            'base_price' => '55.00',
            'is_available_in_pos' => true,
        ]);
        DigitalMenu::create(['branch_id' => $otro['branch']->id, 'slug' => 'cafe-norte', 'is_active' => true]);
    });
    app(TenantContext::class)->forget();

    // El menú de la fonda NO muestra el platillo del café.
    $this->get('/m/fonda-centro')
        ->assertOk()
        ->assertSee('Enchiladas suizas')
        ->assertDontSee('Croissant secreto');
});
