<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LA PANTALLA DE TRABAJOS DE IMPRESIÓN (shell web)
 *
 * La ruta sólo entrega el shell de Inertia: los trabajos llegan de `/api/v1/print-jobs`, y el permiso de verlos
 * (`printing.jobs.view`) lo aplica esa API, no esta ruta. Lo que se cuida aquí es lo que ninguna prueba de la API ve: que
 * la pantalla exista, que exija sesión y que monte SU página. Un 200 del shell con otro componente —o con uno que no
 * existe— es una pantalla en blanco con la suite en verde (la lección de `FoundationSmokeTest`).
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

it('la pantalla de trabajos de impresión responde a un usuario del negocio y monta su página', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin/impresoras/trabajos')
        ->assertOk();

    expect($respuesta->viewData('page')['component'])->toBe('Admin/Printers/Jobs');
});

it('sin sesión, la pantalla de trabajos de impresión manda al login', function () {
    $this->get('/admin/impresoras/trabajos')->assertRedirect(route('login'));
});
