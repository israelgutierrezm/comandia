<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ProvisionTenantRoles;
use App\Modules\Identity\Domain\PermissionCatalog;
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
 * MATRIZ DE AUTORIZACIÓN COMPLETA DE `Catalog` Y `Costing`
 *
 * La matriz de la Iteración 1 verificaba una **muestra** de permisos —los de máxima auditoría—. Ésta cubre los
 * **dieciséis** permisos de estos dos módulos, y tiene un candado que lo demuestra: si D72 agregara un permiso a
 * cualquiera de los dos y nadie lo pusiera aquí, la última prueba falla.
 *
 * Se verifica a través del **servicio de autorización** y no leyendo las plantillas: lo que importa no es lo que
 * la plantilla dice, es lo que el sistema responde. Y siempre por ROL ACTIVO, nunca por suma de roles (D9).
 *
 * El reparto es el de D98, y su lógica en una frase: **el costo es información del negocio y el precio no**. Un
 * mesero dice los precios en voz alta y no necesita saber el margen; un almacenista tiene la factura del
 * proveedor en la mano y captura costos, pero no ve lo que se gana.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();

    app(ProvisionTenantRoles::class)->provision();

    $this->user = User::factory()->create();
    $this->membership = TenantMembership::factory()->create([
        'user_id' => $this->user->id,
        'has_all_branches' => true,
    ]);

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
 * La matriz: `[permiso, roles que lo tienen, roles que no]`.
 *
 * Como tabla y no como una prueba por caso, porque así se lee de un vistazo qué puede hacer cada puesto con el
 * catálogo — que es la pregunta que un tenant hace al configurar su negocio.
 */
$matriz = (function (): array {
    $O = RoleTemplates::OWNER;
    $G = RoleTemplates::MANAGER;
    $C = RoleTemplates::CASHIER;
    $M = RoleTemplates::WAITER;
    $MC = RoleTemplates::WAITER_WITH_CHARGE;
    $A = RoleTemplates::WAREHOUSE_KEEPER;

    return [
        // ---- Catálogo: ver es de todos, administrar es de mandos ----
        //
        // Ver artículos lo necesita literalmente todo el mundo: el mesero para capturar, el cajero para cobrar,
        // el almacenista para saber qué recibe.
        'ver artículos' => ['catalog.articles.view', [$O, $G, $C, $M, $MC, $A], []],

        'crear y editar artículos' => ['catalog.articles.manage', [$O, $G], [$C, $M, $MC, $A]],
        'archivar artículos' => ['catalog.articles.archive', [$O, $G], [$C, $M, $MC, $A]],

        // El PRECIO lo ve quien lo dice en voz alta o lo cobra. El almacenista NO: no vende.
        'ver precios' => ['catalog.prices.view', [$O, $G, $C, $M, $MC], [$A]],

        // Zona de auditoría (§6.7): todo cambio deja historial inmutable y bitácora técnica.
        'CAMBIAR precios' => ['catalog.prices.update', [$O, $G], [$C, $M, $MC, $A]],
        'ver el historial de precios' => ['catalog.prices.history.view', [$O, $G], [$C, $M, $MC, $A]],

        // Datos de referencia del catálogo: se LEEN con `catalog.articles.view` (D99) y se administran aquí.
        'administrar categorías' => ['catalog.categories.manage', [$O, $G], [$C, $M, $MC, $A]],
        'administrar etiquetas' => ['catalog.tags.manage', [$O, $G], [$C, $M, $MC, $A]],
        'administrar unidades' => ['catalog.units.manage', [$O, $G], [$C, $M, $MC, $A]],
        'administrar modificadores' => ['catalog.modifiers.manage', [$O, $G], [$C, $M, $MC, $A]],

        // ---- Costeo: el costo es información del negocio ----
        //
        // El almacenista ve recetas porque necesita saber qué consume cada producción; el mesero no tiene nada
        // que hacer con la composición de un platillo.
        'ver recetas' => ['costing.recipes.view', [$O, $G, $A], [$C, $M, $MC]],
        'crear y editar recetas' => ['costing.recipes.manage', [$O, $G], [$C, $M, $MC, $A]],

        // El almacenista SÍ captura costos: es quien recibe la mercancía y tiene la factura del proveedor en la
        // mano. Negárselo obligaría a que un gerente teclee costos que no vio (D98).
        'ver costos' => ['costing.costs.view', [$O, $G, $A], [$C, $M, $MC]],
        'capturar costos' => ['costing.costs.update', [$O, $G, $A], [$C, $M, $MC]],
        'ver el historial de costos' => ['costing.costs.history.view', [$O, $G, $A], [$C, $M, $MC]],

        // Y NO ve el precio sugerido ni el margen: ve lo que cuesta, no lo que se gana.
        'ver precios sugeridos y márgenes' => ['costing.suggested_prices.view', [$O, $G], [$C, $M, $MC, $A]],
    ];
})();

dataset('matriz de catálogo', $matriz);

it('autoriza y niega según la matriz', function (string $permiso, array $permitidos, array $negados) {
    foreach ($permitidos as $rol) {
        ($this->comoRol)($rol);

        expect($this->authorize->allows($permiso))
            ->toBeTrue("«{$rol}» debería poder «{$permiso}»");
    }

    foreach ($negados as $rol) {
        ($this->comoRol)($rol);

        expect($this->authorize->allows($permiso))
            ->toBeFalse("«{$rol}» NO debería poder «{$permiso}»");
    }
})->with('matriz de catálogo');

it('cada fila de la matriz cubre los seis roles', function () use ($matriz) {
    // Autoverificación: una fila que olvidara un rol dejaría ese caso sin verificar, y el olvido no se notaría
    // porque la prueba seguiría verde.
    foreach ($matriz as $descripcion => [$permiso, $permitidos, $negados]) {
        expect(array_merge($permitidos, $negados))
            ->toHaveCount(6, "La fila «{$descripcion}» ({$permiso}) no cubre los seis roles");

        expect(array_intersect($permitidos, $negados))
            ->toBe([], "La fila «{$descripcion}» tiene un rol en las dos columnas");
    }
});

it('la matriz cubre TODOS los permisos de Catalog y Costing', function () use ($matriz) {
    // EL CANDADO. D72 permite que cada iteración agregue permisos de su propio módulo; sin esta comprobación,
    // uno nuevo quedaría sin reparto verificado y la matriz seguiría en verde presentándose como completa.
    // `forModules` ya devuelve los nombres aplanados: es el catálogo cerrado de D10 preguntado directamente,
    // no una copia de la lista.
    $delCatalogo = PermissionCatalog::forModules(['Catalog', 'Costing']);

    $enLaMatriz = array_map(fn (array $fila): string => $fila[0], array_values($matriz));

    expect($delCatalogo)->not->toBeEmpty();

    $faltantes = array_diff($delCatalogo, $enLaMatriz);

    expect($faltantes)->toBe([], sprintf(
        "Estos permisos de Catalog/Costing NO están en la matriz:\n  - %s",
        implode("\n  - ", $faltantes),
    ));

    // Y a la inversa: nada en la matriz que no sea un permiso real de estos módulos, que detectaría un typo
    // convirtiendo una fila en una prueba de que nadie tiene un permiso inexistente.
    expect(array_diff($enLaMatriz, $delCatalogo))->toBe([]);
});
