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
 * MATRIZ DE AUTORIZACIÓN DE `Inventory` Y `Purchasing`
 *
 * Exhaustiva desde el primer paso, y con candado de cobertura: si una iteración agrega un permiso a cualquiera
 * de los dos módulos y nadie lo reparte, la última prueba falla. Es el patrón de D128, que en la Iteración 2 se
 * escribió al final; aquí se escribe al principio porque los dieciocho permisos ya existen en el catálogo
 * cerrado y repartirlos no depende de que el código exista.
 *
 * Se verifica por el **servicio de autorización** y no leyendo las plantillas: lo que importa no es lo que la
 * plantilla dice, es lo que el sistema responde. Siempre por rol activo, nunca por suma de roles (D9).
 *
 * ## La lógica del reparto, en una frase
 *
 * **El almacenista opera el inventario; quien está en el punto de venta no lo toca.** De ahí sale casi todo,
 * incluido que el cajero **no** vea existencias: el inventario del sistema es teórico (§6.2) y decidir una
 * venta con él contradice la regla de que la venta siempre procede.
 *
 * Las tres excepciones que conviene entender:
 *
 *   - **Cerrar un conteo** no es del almacenista, aunque iniciarlo sí: cerrar aplica diferencias masivas, y
 *     quien cuenta no decide que su conteo es la verdad.
 *   - **Autorizar mermas sobre el umbral** tampoco, aunque registrarlas sí. Si quien registra pudiera
 *     autorizar, el umbral no defendería nada — la misma razón por la que nadie edita sus propios roles.
 *   - **Los precios de proveedor** sí, porque recibe la mercancía con la factura en la mano.
 *
 * Al escribir esta matriz me equivoqué en dos filas —el cajero viendo existencias y el almacenista sin ver
 * precios de proveedor— y las plantillas de la Iteración 1 tenían mejor argumento en las dos. Quedan anotadas
 * donde ocurrieron, porque la próxima vez la tentación será la misma.
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
 */
$matriz = (function (): array {
    $O = RoleTemplates::OWNER;
    $G = RoleTemplates::MANAGER;
    $C = RoleTemplates::CASHIER;
    $M = RoleTemplates::WAITER;
    $MC = RoleTemplates::WAITER_WITH_CHARGE;
    $A = RoleTemplates::WAREHOUSE_KEEPER;

    return [
        // ---- Consulta ----
        //
        // Ni el cajero ni el mesero ven existencias, y al escribir esta matriz lo puse al revés: pensé que
        // «¿queda pastel?» se pregunta en la caja. La plantilla de la Iteración 1 tiene mejor argumento y se
        // respeta.
        //
        // El inventario del sistema es **teórico** y se reconcilia con conteos (§6.2). El pastel que queda se
        // ve en la vitrina, no en una pantalla que puede llevar tres días de atraso. Y enseñárselo a quien
        // cobra invita justo a lo que §6.2 prohíbe: tomar una decisión de venta a partir de un número de
        // inventario. La venta siempre procede.
        'ver existencias' => ['inventory.stock.view', [$O, $G, $A], [$C, $M, $MC]],

        // El kardex dice QUIÉN movió qué: es información de control, no de operación.
        'consultar el kardex' => ['inventory.kardex.view', [$O, $G, $A], [$C, $M, $MC]],

        // ---- Movimientos manuales ----
        'registrar entradas' => ['inventory.entries.create', [$O, $G, $A], [$C, $M, $MC]],
        'registrar salidas' => ['inventory.exits.create', [$O, $G, $A], [$C, $M, $MC]],
        'registrar ajustes' => ['inventory.adjustments.create', [$O, $G, $A], [$C, $M, $MC]],

        // ---- Conteos (paso 5) ----
        'iniciar conteos' => ['inventory.counts.create', [$O, $G, $A], [$C, $M, $MC]],

        // Cerrar un conteo APLICA diferencias masivas: es la acción con más efecto del módulo, y la separación
        // respecto de «iniciar» existe para que quien cuenta no sea quien decide que su conteo es la verdad.
        'cerrar conteos y aplicar diferencias' => ['inventory.counts.close', [$O, $G], [$C, $M, $MC, $A]],

        // SÓLO el propietario, y es el único permiso del catálogo que se le quita al gerente por esta razón: es el
        // gerente quien CIERRA, así que si además pudiera autorizar se firmaría a sí mismo un castigo de inventario
        // de cualquier tamaño. Con las mermas no hacía falta —ahí registra el almacenista y autoriza el gerente—
        // pero aquí el que ejecuta ya es el gerente, y el control tiene que subir un nivel.
        //
        // Cuesta algo real: un cierre con descuadre grande espera al propietario. Se acepta porque es exactamente
        // lo que debe pasar cuando se van a dar por perdidos cincuenta mil pesos de mercancía.
        'autorizar cierres de conteo sobre el umbral' => [
            'inventory.counts.authorize_above_threshold', [$O], [$C, $M, $MC, $A, $G],
        ],

        // ---- Mermas (paso 4) ----
        'registrar mermas' => ['inventory.waste.create', [$O, $G, $A], [$C, $M, $MC]],

        // NO el almacenista, aunque registre las mermas: si quien la registra pudiera autorizarla, el umbral
        // no defendería nada.
        'autorizar mermas sobre el umbral' => ['inventory.waste.authorize_above_threshold', [$O, $G], [$C, $M, $MC, $A]],

        // ---- Transferencias (paso 6) ----
        'solicitar transferencias' => ['inventory.transfers.request', [$O, $G, $A], [$C, $M, $MC]],
        'autorizar transferencias' => ['inventory.transfers.authorize', [$O, $G], [$C, $M, $MC, $A]],
        'preparar transferencias' => ['inventory.transfers.prepare', [$O, $G, $A], [$C, $M, $MC]],
        'enviar transferencias' => ['inventory.transfers.ship', [$O, $G, $A], [$C, $M, $MC]],
        'recibir transferencias' => ['inventory.transfers.receive', [$O, $G, $A], [$C, $M, $MC]],

        // ---- Lotes (paso 3) ----
        'administrar lotes y caducidades' => ['inventory.lots.manage', [$O, $G, $A], [$C, $M, $MC]],

        // ---- Compras (pasos 8 y 9) ----
        'ver proveedores' => ['purchasing.suppliers.view', [$O, $G, $A], [$C, $M, $MC]],
        'crear y editar proveedores' => ['purchasing.suppliers.manage', [$O, $G], [$C, $M, $MC, $A]],

        // El almacenista captura la recepción: es quien recibe la mercancía y tiene la factura en la mano. Es
        // la misma lógica que le dio `costing.costs.update` en la Iteración 2 (D98).
        'registrar recepciones de compra' => ['purchasing.receipts.create', [$O, $G, $A], [$C, $M, $MC]],

        // El almacenista SÍ los ve, y también aquí mi primera versión estaba equivocada: los había dejado como
        // información comercial reservada. Pero es quien recibe la mercancía **con la factura en la mano** —
        // literalmente está viendo esos precios impresos. Ocultárselos en el sistema sería teatro, y de paso le
        // impediría notar la subida que el catálogo de precios existe para detectar (D26).
        //
        // Es la misma lógica que le dio la captura de costos en la Iteración 2 (D98). Lo que NO ve sigue siendo
        // el margen: ve lo que cuesta, no lo que se gana.
        'consultar precios de proveedores' => ['purchasing.supplier_prices.view', [$O, $G, $A], [$C, $M, $MC]],
    ];
})();

dataset('matriz de inventarios', $matriz);

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
})->with('matriz de inventarios');

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

it('la matriz cubre TODOS los permisos de Inventory y Purchasing', function () use ($matriz) {
    // EL CANDADO. D72 permite que cada iteración agregue permisos de su módulo; sin esta comprobación, uno
    // nuevo quedaría sin reparto verificado y la matriz seguiría en verde presentándose como completa.
    //
    // Aquí importa más que en la Iteración 2: `purchasing.receipts.confirm` se va a agregar en el paso 9
    // (D153), y este candado es lo que garantiza que no llegue sin reparto.
    $delCatalogo = PermissionCatalog::forModules(['Inventory', 'Purchasing']);

    $enLaMatriz = array_map(fn (array $fila): string => $fila[0], array_values($matriz));

    expect($delCatalogo)->not->toBeEmpty();

    $faltantes = array_diff($delCatalogo, $enLaMatriz);

    expect($faltantes)->toBe([], sprintf(
        "Estos permisos de Inventory/Purchasing NO están en la matriz:\n  - %s",
        implode("\n  - ", $faltantes),
    ));

    // Y a la inversa: nada en la matriz que no sea un permiso real de estos módulos, que detectaría un typo
    // convirtiendo una fila en una prueba de que nadie tiene un permiso inexistente.
    expect(array_diff($enLaMatriz, $delCatalogo))->toBe([]);
});
