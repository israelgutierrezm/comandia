<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PERMISOS QUE SE PODÍAN OTORGAR Y NO HACÍAN NADA (Iteración 5, D296–D298)
 *
 * ## Por qué esto es peor que un permiso que falta
 *
 * Porque **parece protección**. Un negocio que revisa sus roles, ve «Cobrar a crédito» desmarcado en el mesero y cree
 * haberlo impedido. Lo encontró la segunda pregunta obligatoria de cierre de la Iteración 4: de 132 permisos, tres no
 * los comprobaba nadie.
 *
 * ## Lo que estas pruebas fijan
 *
 * Que **fiar exige su permiso** (D296), que **reimprimir exige el suyo** —y no el de comandar, que es otro acto—
 * (D297), y que el catálogo ya no arrastra un permiso muerto (D298).
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

afterEach(fn () => app(TenantContext::class)->forget());

it('el catálogo ya no declara un permiso que nadie usa', function () {
    // Cerrar el turno ya exige `pos.sessions.close`, que sí se comprueba. «Cerrar el corte» como acto separado no
    // existe: el corte se CALCULA del diario y no se almacena (§6.5), así que no hay nada que cerrar.
    expect(PermissionCatalog::names())->not->toContain('finance.cuts.close');
});

it('fiar exige `pos.credit.charge_to_customer`, y el mesero simple no lo tiene', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $permisos = fn (string $rol): array => Role::query()
        ->where('name', $rol)
        ->firstOrFail()
        ->permissionNames();

    // El reparto que las plantillas ya declaraban desde la Iteración 1, y que hasta hoy no significaba nada.
    expect($permisos(RoleTemplates::CASHIER))->toContain('pos.credit.charge_to_customer');
    expect($permisos(RoleTemplates::MANAGER))->toContain('pos.credit.charge_to_customer');

    // Ni el mesero ni el mesero CON COBRO. Es una distinción deliberada de las plantillas y vale la pena nombrarla:
    // cobrar es recibir dinero, fiar es prestarlo. Hasta ahora los dos podían fiar igual, porque el permiso no lo
    // comprobaba nadie — que es exactamente lo que hace peligroso un permiso declarado y no exigido.
    expect($permisos(RoleTemplates::WAITER))->not->toContain('pos.credit.charge_to_customer');
    expect($permisos(RoleTemplates::WAITER_WITH_CHARGE))->not->toContain('pos.credit.charge_to_customer');

    app(TenantContext::class)->forget();
});

it('la reimpresión exige el permiso de reimprimir, no el de comandar', function () {
    // Son dos actos distintos: comandar manda a preparar algo nuevo; reimprimir saca otra copia de algo que ya salió,
    // y una comanda duplicada es un platillo duplicado. La ruta pedía el de comandar mientras el permiso declarado
    // para esto no lo usaba nadie.
    $ruta = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'api.v1.pos-tickets.reprint');

    expect($ruta)->not->toBeNull();
    expect($ruta->gatherMiddleware())->toContain('can.write:printing.jobs.reprint');
    expect($ruta->gatherMiddleware())->not->toContain('can.write:pos.orders.send_to_area');
});
