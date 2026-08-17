<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Authorization\Authorize;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * MATRIZ DE AUTORIZACIÓN: permiso × rol activo × sucursal.
 *
 * Exigida por ARQUITECTURA_MAESTRA §11 para las acciones sensibles. Verifica el reparto real
 * de los seis roles plantilla a través del servicio de autorización, no leyendo la definición:
 * lo que importa no es lo que la plantilla dice, es lo que el sistema responde.
 *
 * Los permisos elegidos no son una muestra al azar. Son los que §6.3 y §9 señalan como zona
 * de máxima auditoría —cobrar, descuentos, cortesías, cancelar comandado, abrir cajón,
 * retirar efectivo— más los que definen la frontera entre puestos.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();
    $this->foreignBranch = Branch::factory()->create();

    app(ProvisionTenantRoles::class)->provision();

    $this->user = User::factory()->create();
    $this->membership = TenantMembership::factory()->create([
        'user_id' => $this->user->id,
        'has_all_branches' => false,
    ]);
    $this->membership->branchScopes()->create(['branch_id' => $this->branch->id]);

    $this->authorize = app(Authorize::class);

    $this->comoRol = function (string $nombreRol): void {
        $rol = Role::query()->where('name', $nombreRol)->firstOrFail();

        app(ContextHolder::class)->set(RequestContext::forMember(
            tenant: $this->tenant,
            user: $this->user,
            membership: $this->membership,
            activeRole: $rol,
            activeBranch: $this->branch,
        ));
    };
});

afterEach(function () {
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

/**
 * La matriz. `true` = autoriza, `false` = niega.
 *
 * Escrita como tabla y no como una prueba por caso porque así se lee de un vistazo qué puede
 * hacer cada puesto — que es exactamente la pregunta que un tenant hace al configurar su
 * negocio, y la decisión con más impacto operativo del diseño.
 */
$matriz = (function (): array {
    $O = RoleTemplates::OWNER;
    $G = RoleTemplates::MANAGER;
    $C = RoleTemplates::CASHIER;
    $M = RoleTemplates::WAITER;
    $MC = RoleTemplates::WAITER_WITH_CHARGE;
    $A = RoleTemplates::WAREHOUSE_KEEPER;

    return [
        //                                        Propietario Gerente Cajero Mesero MeseroCobro Almacenista
        'capturar una orden' => ['pos.orders.create', [$O, $G, $C, $M, $MC], [$A]],
        'comandar a las áreas' => ['pos.orders.send_to_area', [$O, $G, $C, $M, $MC], [$A]],

        // La frontera de D29: cobrar es un permiso, no un puesto.
        'COBRAR una cuenta' => ['pos.accounts.charge', [$O, $G, $C, $MC], [$M, $A]],

        // Zona de máxima auditoría (§6.3): sólo mandos, y por eso existe el PIN.
        'aplicar descuento a la cuenta' => ['pos.discounts.apply_account', [$O, $G], [$C, $M, $MC, $A]],
        'registrar una cortesía' => ['pos.discounts.courtesy', [$O, $G], [$C, $M, $MC, $A]],
        'cancelar un item comandado' => ['pos.items.cancel_commanded', [$O, $G], [$C, $M, $MC, $A]],
        'retirar efectivo de la caja' => ['pos.sessions.withdraw', [$O, $G], [$C, $M, $MC, $A]],

        // Abrir el cajón sí lo tienen quienes cobran: es parte de cobrar en efectivo.
        'abrir el cajón de dinero' => ['pos.cash_drawer.open', [$O, $G, $C, $MC], [$M, $A]],

        // Inventario: el almacenista opera, pero no se autoriza a sí mismo.
        'registrar entrada de inventario' => ['inventory.entries.create', [$O, $G, $A], [$C, $M, $MC]],
        'cerrar un conteo físico' => ['inventory.counts.close', [$O, $G], [$C, $M, $MC, $A]],
        'autorizar merma sobre umbral' => ['inventory.waste.authorize_above_threshold', [$O, $G], [$C, $M, $MC, $A]],

        // Administración y evidencia.
        'consultar la bitácora' => ['audit.entries.view', [$O, $G], [$C, $M, $MC, $A]],
        'ver PII del personal' => ['identity.employee_profiles.view_sensitive', [$O, $G], [$C, $M, $MC, $A]],
        'cambiar precios' => ['catalog.prices.update', [$O, $G], [$C, $M, $MC, $A]],

        // Sólo el propietario: comercial y secretos financieros.
        'ver la suscripción' => ['tenancy.subscription.view', [$O], [$G, $C, $M, $MC, $A]],
        'eliminar un rol' => ['identity.roles.delete', [$O], [$G, $C, $M, $MC, $A]],
    ];
})();

dataset('matriz', $matriz);

it('autoriza y niega según la matriz', function (string $permiso, array $permitidos, array $negados) {
    foreach ($permitidos as $rol) {
        ($this->comoRol)($rol);

        expect($this->authorize->can($permiso))
            ->toBeTrue("«{$rol}» debería poder «{$permiso}»");
    }

    foreach ($negados as $rol) {
        ($this->comoRol)($rol);

        expect($this->authorize->can($permiso))
            ->toBeFalse("«{$rol}» NO debería poder «{$permiso}»");
    }
})->with('matriz');

it('la matriz cubre los seis roles en cada fila', function () use ($matriz) {
    // Autoverificación: una fila que olvidara un rol dejaría ese caso sin verificar, y el
    // olvido no se notaría porque la prueba seguiría verde.
    foreach ($matriz as $descripcion => [$permiso, $permitidos, $negados]) {
        expect(array_merge($permitidos, $negados))
            ->toHaveCount(6, "La fila «{$descripcion}» ({$permiso}) no cubre los seis roles");

        // Y que ningún rol aparezca a la vez como permitido y negado.
        expect(array_intersect($permitidos, $negados))
            ->toBe([], "La fila «{$descripcion}» tiene un rol en las dos columnas");
    }
});

it('el alcance de sucursal se aplica sobre el permiso ya concedido', function () {
    // Las dos dimensiones son independientes y ambas tienen que cumplirse: tener el permiso
    // no da acceso a una sucursal fuera de alcance, y estar en la sucursal no da el permiso.
    ($this->comoRol)(RoleTemplates::MANAGER);

    expect($this->authorize->can('pos.accounts.charge', $this->branch->id))->toBeTrue();
    expect($this->authorize->can('pos.accounts.charge', $this->foreignBranch->id))->toBeFalse();

    ($this->comoRol)(RoleTemplates::WAITER);

    // Sin el permiso, la sucursal correcta no lo suple.
    expect($this->authorize->can('pos.accounts.charge', $this->branch->id))->toBeFalse();
});
