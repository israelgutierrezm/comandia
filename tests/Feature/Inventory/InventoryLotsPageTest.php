<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Str;

/**
 * LA PÁGINA DE LOTES DE UN ARTÍCULO (shell web)
 *
 * Como el kardex, la ruta sólo entrega la página con el ULID del artículo: los datos y la autorización son de
 * `/api/v1` (D59). Aquí se comprueba lo que es de la ruta: que exista para quien inició sesión en el negocio, que le
 * diga a la página de qué artículo se habla, y que un ULID mal formado no llegue a la página.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda de los Lotes',
        ownerEmail: 'lotes@fonda.mx',
        ownerFirstName: 'Lucía',
        ownerPaternalSurname: 'Treviño',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('entrega la página de lotes con el ULID del artículo', function () {
    $ulid = (string) Str::ulid();

    $pagina = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get("/admin/existencias/{$ulid}/lotes")
        ->assertOk()
        ->viewData('page');

    expect($pagina['component'])->toBe('Admin/Inventory/Stock/Lots')
        ->and($pagina['props']['articleUlid'])->toBe($ulid);
});

it('no entrega la página con un ULID mal formado', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin/existencias/no-es-un-ulid/lotes')
        ->assertNotFound();
});

it('sin sesión manda al login', function () {
    $this->get('/admin/existencias/'.Str::ulid().'/lotes')->assertRedirect(route('login'));
});
