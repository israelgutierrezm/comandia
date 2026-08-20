<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL ALCANCE POR SUCURSAL EN EL POS
 *
 * ## De dónde salió esto
 *
 * De abrir el navegador, no de la suite. Con Roma Norte como sucursal activa, el desplegable de la pantalla de caja
 * ofrecía la terminal de **Polanco** —las dos se llamaban «Caja 1»— y abrirla devolvió **201**. El turno quedó en
 * Polanco.
 *
 * ## Por qué el `tenant_id` no protege de esto
 *
 * Es el mismo negocio. El aislamiento de tenant, que es la línea que más se vigila en este proyecto, no dice nada sobre
 * dos sucursales del mismo tenant. Quien lo dice es `membership_branch_scopes`, y su comprobación es
 * `canOperateInBranch()`.
 *
 * ## El mecanismo existía y el POS no lo usaba
 *
 * `ResolveTenantContext::resolveTerminal()` hace las tres comprobaciones correctas —activa, coherente con la sucursal
 * activa, dentro del alcance— y su propio encabezado cuenta que una versión anterior ignoraba el header en silencio.
 * Pero eso vigila la **cabecera** `X-Terminal`. Abrir caja manda `terminal_ulid` en el **cuerpo**, y por ahí no pasa
 * nada de eso: la validación sólo comprueba que la terminal exista en el negocio y esté activa.
 *
 * `Authorize::authorize($permiso, ?int $branchId = null)` acepta la sucursal como **segundo argumento opcional**, y ese
 * `= null` es la forma que toma el hueco: cada llamada que lo omite se salta la comprobación sin que nada avise.
 * Inventarios sí lo pasa (`AssertsWarehouseScope`) y tiene su prueba desde la Iteración 3; el POS no.
 *
 * ## Lo que fijan estas pruebas
 *
 * Que un cajero con alcance a una sola sucursal no puede abrir caja en la ajena, y que un mesero no puede abrir cuenta
 * en ella. Son las dos puertas de entrada del POS: de la sesión cuelgan los cobros, los retiros y el corte; de la
 * cuenta, las órdenes y las comandas.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda La Comandia',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->ajena = Branch::factory()->create(['code' => 'POLA', 'name' => 'Polanco']);

    // Dos terminales que se llaman IGUAL, porque el nombre es único por sucursal y no por negocio. Es exactamente lo
    // que había en el negocio de demostración y lo que hizo que el desplegable no se pudiera leer.
    $this->propia = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $this->deLaAjena = Terminal::create(['branch_id' => $this->ajena->id, 'code' => 'POLA1', 'name' => 'Caja 1']);

    /**
     * Alguien con alcance a UNA sola sucursal.
     *
     * Tiene que ser una persona distinta del propietario: la membresía es única por (negocio, usuario) y la del
     * propietario alcanza todo el negocio, así que sobre ella no se puede probar nada de esto.
     */
    $this->limitado = function (string $rol, string $codigo, string $correo): User {
        $persona = User::factory()->create([
            'email' => $correo,
            'first_name' => 'Beto',
            'paternal_surname' => 'Nava',
        ]);

        $membresia = TenantMembership::factory()->create([
            'user_id' => $persona->id,
            'employee_code' => $codigo,
            'has_all_branches' => false,
        ]);

        $membresia->branchScopes()->create(['branch_id' => $this->branch->id]);

        $papel = Role::query()->where('name', $rol)->firstOrFail();
        $persona->syncRoles([$papel]);
        $membresia->update(['default_role_id' => $papel->id]);

        return $persona;
    };
});

afterEach(fn () => app(TenantContext::class)->forget());

it('un cajero no abre caja en una sucursal fuera de su alcance', function () {
    $cajero = ($this->limitado)(RoleTemplates::CASHIER, 'C001', 'cajero@fonda.mx');

    app(TenantContext::class)->forget();

    // En la suya sí. La primera aserción importa tanto como la segunda: sin ella, un 403 por cualquier otro motivo
    // —un permiso que falta, un rol mal sembrado— se leería como que el alcance funciona.
    $this->actingAsSpa($cajero, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->propia->ulid,
            'opening_float' => '500.00',
        ])
        ->assertCreated();

    // En la ajena no. Es del mismo negocio, así que el `tenant_id` la deja pasar entera.
    $this->actingAsSpa($cajero, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', [
            'terminal_ulid' => $this->deLaAjena->ulid,
            'opening_float' => '500.00',
        ])
        ->assertForbidden();
});

it('un mesero no abre cuenta en una sucursal fuera de su alcance', function () {
    $mesero = ($this->limitado)(RoleTemplates::WAITER, 'M001', 'mesero@fonda.mx');

    app(TenantContext::class)->forget();

    $this->actingAsSpa($mesero, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->branch->ulid,
            'label' => 'Señor de lentes',
        ])
        ->assertCreated();

    $this->actingAsSpa($mesero, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', [
            'branch_ulid' => $this->ajena->ulid,
            'label' => 'Señor de lentes',
        ])
        ->assertForbidden();
});
