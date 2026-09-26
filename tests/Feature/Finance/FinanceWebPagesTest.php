<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LAS PANTALLAS WEB DE FINANZAS (shell de Inertia)
 *
 * Estas rutas sólo entregan la página: los datos y la autorización viven en `/api/v1` (D59), y ahí se prueban. Lo que
 * se vigila aquí es lo que la API no puede ver: que cada ruta exista con el nombre que usa la navegación, que responda a
 * una persona autenticada del negocio y que renderice SU componente. Un 200 del shell con el componente equivocado se ve
 * igual de verde, y es la lección de `FoundationSmokeTest`: un 200 no prueba que la página sea la correcta.
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

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

dataset('pantallas de finanzas', [
    'métodos de pago' => ['admin.finance.payment-methods', '/admin/finanzas/metodos-de-pago', 'Admin/Finance/PaymentMethods'],
    'gastos' => ['admin.finance.expenses', '/admin/finanzas/gastos', 'Admin/Finance/Expenses'],
    'depósitos' => ['admin.finance.deposits', '/admin/finanzas/depositos', 'Admin/Finance/Deposits'],
    'propinas' => ['admin.finance.tips', '/admin/finanzas/propinas', 'Admin/Finance/Tips'],
    'movimientos' => ['admin.finance.journal', '/admin/finanzas/movimientos', 'Admin/Finance/Journal'],
]);

it('entrega la pantalla con su componente a una persona del negocio', function (string $nombre, string $url, string $componente) {
    // El nombre de la ruta y su URL, fijados: la barra lateral enlaza por URL y la tabla de rutas del shell los repite.
    expect(route($nombre, absolute: false))->toBe($url);

    $pagina = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get($url)
        ->assertOk()
        ->viewData('page');

    expect($pagina['component'])->toBe($componente);
})->with('pantallas de finanzas');

it('sin sesión manda al login', function (string $nombre, string $url) {
    $this->withoutVite()->get($url)->assertRedirect(route('login'));
})->with('pantallas de finanzas');
