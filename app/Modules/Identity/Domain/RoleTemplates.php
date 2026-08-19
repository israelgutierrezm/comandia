<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Roles plantilla que se crean al dar de alta un tenant (D10).
 *
 * Son **editables y eliminables** por el tenant, salvo *Propietario*. Criterio de
 * reparto: mínimo privilegio (§10.3).
 *
 * ## Estado del reparto (D71)
 *
 * Los seis roles nacen en la Iteración 1, pero el reparto de los permisos de POS,
 * inventario y finanzas **se revisa al construir cada módulo**: hoy se estaría
 * decidiendo si un mesero puede cancelar un platillo ya comandado sin haber visto el
 * flujo del POS en pantalla. Las iteraciones 3, 4 y 5 pueden ajustar el reparto de sus
 * propios permisos, y cada ajuste se registra como decisión.
 *
 * ## Las dos plantillas de mesero
 *
 * Existen porque cobrar es un **permiso**, no un puesto (D29): el mismo rol base con y
 * sin la capacidad de cerrar cuenta. Un negocio donde el mesero cobra y otro donde
 * cobra el cajero son el mismo sistema con distinta configuración de roles.
 */
final class RoleTemplates
{
    public const OWNER = 'Propietario';

    public const MANAGER = 'Gerente';

    public const CASHIER = 'Cajero';

    public const WAITER = 'Mesero';

    public const WAITER_WITH_CHARGE = 'Mesero con cobro';

    public const WAREHOUSE_KEEPER = 'Almacenista';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::OWNER,
            self::MANAGER,
            self::CASHIER,
            self::WAITER,
            self::WAITER_WITH_CHARGE,
            self::WAREHOUSE_KEEPER,
        ];
    }

    /**
     * Definición de cada plantilla.
     *
     * @return array<string, array{description: string, is_system: bool, requires_two_factor: bool, permissions: list<string>|null}>
     *                                                                                                                               `permissions` null = todos los del catálogo
     */
    public static function definitions(): array
    {
        return [
            self::OWNER => [
                'description' => 'Todos los permisos. No se puede editar ni eliminar.',
                'is_system' => true,
                // El propietario tiene acceso a todo, incluidas las credenciales de
                // pasarela y la bitácora: es el rol donde 2FA aporta más.
                'requires_two_factor' => true,
                'permissions' => null,
            ],

            self::MANAGER => [
                'description' => 'Opera el negocio completo, sin tocar suscripción ni pasarelas de pago.',
                'is_system' => false,
                'requires_two_factor' => true,
                'permissions' => self::managerPermissions(),
            ],

            self::CASHIER => [
                'description' => 'Cobra cuentas y opera la caja. Sin descuentos ni cancelación de comandado.',
                'is_system' => false,
                'requires_two_factor' => false,
                'permissions' => [
                    'pos.orders.create',
                    'pos.orders.send_to_area',
                    'pos.items.cancel_uncommanded',
                    'pos.accounts.request_bill',
                    'pos.accounts.charge',

                    // Quien cobra necesita ver los métodos para pintar los botones de la caja. Sin esto, la
                    // pantalla de cobro llega sin con qué cobrar.
                    'finance.payment_methods.view',
                    'pos.accounts.split',
                    'pos.accounts.move_items',
                    'pos.accounts.merge',
                    'pos.sessions.open',
                    'pos.sessions.precount',
                    'pos.sessions.close',
                    'pos.cash_drawer.open',
                    'pos.takeout.manage',
                    'pos.credit.charge_to_customer',
                    'customers.customers.view',
                    'customers.customers.manage',
                    'floor.layouts.view',
                    'printing.jobs.view',
                    'printing.jobs.reprint',
                    'notifications.preferences.manage',

                    // Catálogo (Iteración 2, D71): ve artículos y precios porque cobra con ellos.
                    // No ve costos.
                    'catalog.articles.view',
                    'catalog.prices.view',

                    // Deliberadamente FUERA: descuentos, cortesías, cancelación de
                    // comandado y retiros de caja. Son la zona de máxima auditoría
                    // (§6.3) y pasan por autorización con PIN de un superior.
                ],
            ],

            self::WAITER => [
                'description' => 'Captura y comanda. No cobra.',
                'is_system' => false,
                'requires_two_factor' => false,
                'permissions' => self::waiterPermissions(),
            ],

            self::WAITER_WITH_CHARGE => [
                'description' => 'Captura, comanda y además cobra su cuenta (D29).',
                'is_system' => false,
                'requires_two_factor' => false,
                'permissions' => [
                    ...self::waiterPermissions(),
                    'pos.accounts.charge',

                    // Quien cobra necesita ver los métodos para pintar los botones de la caja. Sin esto, la
                    // pantalla de cobro llega sin con qué cobrar.
                    'finance.payment_methods.view',
                    'pos.sessions.open',
                    'pos.sessions.precount',
                    'pos.sessions.close',
                    'pos.cash_drawer.open',
                ],
            ],

            self::WAREHOUSE_KEEPER => [
                'description' => 'Opera inventario y recibe compras. Sin autorizaciones sobre umbral.',
                'is_system' => false,
                'requires_two_factor' => false,
                'permissions' => [
                    'inventory.stock.view',
                    'inventory.kardex.view',
                    'inventory.entries.create',
                    'inventory.exits.create',
                    'inventory.adjustments.create',
                    'inventory.counts.create',
                    'inventory.waste.create',
                    'inventory.production.create',
                    'inventory.transfers.request',
                    'inventory.transfers.prepare',
                    'inventory.transfers.ship',
                    'inventory.transfers.receive',
                    'inventory.lots.manage',
                    'purchasing.suppliers.view',
                    'purchasing.receipts.create',
                    // Confirmar NO: aplicar una recepción mueve existencia y fija el costo de la que salen todos los
                    // precios sugeridos. Quien recibe la mercancía captura la factura; aplicarla es de quien responde
                    // por el inventario. Misma frontera que cerrar un conteo (D179).
                    'purchasing.supplier_prices.view',
                    'catalog.articles.view',
                    'notifications.preferences.manage',

                    // Costos (Iteración 2, D71). El almacenista SÍ los ve y SÍ los captura: es quien
                    // recibe la mercancía y tiene la factura del proveedor en la mano. Negarle la
                    // captura obligaría a que un gerente teclee costos que no vio, que es peor para
                    // la calidad del dato y para la trazabilidad.
                    'costing.costs.view',
                    'costing.costs.update',
                    'costing.costs.history.view',
                    'costing.recipes.view',

                    // FUERA: precios sugeridos y márgenes. Ve lo que cuesta, no lo que se gana.
                    // FUERA: cerrar conteos (aplica diferencias al inventario),
                    // autorizar mermas sobre umbral y autorizar transferencias. Quien
                    // opera el almacén no se autoriza a sí mismo las diferencias.
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function waiterPermissions(): array
    {
        return [
            'pos.orders.create',
            'pos.orders.send_to_area',
            'pos.items.cancel_uncommanded',
            'pos.accounts.request_bill',
            'pos.accounts.split',
            'pos.accounts.move_items',
            'pos.accounts.merge',
            'pos.takeout.manage',
            'customers.customers.view',
            'customers.customers.manage',
            'floor.layouts.view',
            'floor.tables.join',
            'printing.jobs.view',
            'notifications.preferences.manage',

            // Catálogo, agregados en la Iteración 2. D71 previó exactamente esto: los seis roles
            // plantilla se definen desde la Iteración 1 y **el reparto operativo se afina en la
            // iteración que construye cada módulo**, porque decidirlo antes de ver el flujo en
            // pantalla sería decidirlo a ciegas.
            //
            // Un mesero ve artículos y precios —los dice en voz alta y los teclea en la orden— y NO
            // ve costos: el costo es información sensible del negocio, y quien toma la orden no
            // necesita saber el margen del platillo.
            'catalog.articles.view',
            'catalog.prices.view',
        ];
    }

    /**
     * Gerente: todo el catálogo menos lo que corresponde al propietario.
     *
     * Se define por RESTA y no por lista para que un permiso nuevo de cualquier módulo
     * llegue al gerente por defecto. Con una lista explícita, cada iteración tendría
     * que acordarse de añadirlo, y el olvido produciría un gerente que no puede operar
     * su propio negocio — un fallo que sólo se descubre en producción.
     *
     * @return list<string>
     */
    private static function managerPermissions(): array
    {
        $excluidos = [
            // Comercial y contractual: es del propietario.
            'tenancy.subscription.view',
            'tenancy.modules.view',
            // Borrar roles puede dejar al negocio sin quien opere; editarlos sí puede.
            'identity.roles.delete',
            // Credenciales de pasarela de pago: secreto financiero del negocio (§10.4).
            'ecommerce.gateways.configure',
            // Autorizar el cierre de un conteo sobre el umbral: es el gerente quien CIERRA, así que si además
            // pudiera autorizar se firmaría a sí mismo un castigo de inventario de cualquier tamaño y el umbral
            // no defendería nada. Queda en el propietario, que es quien responde por el patrimonio.
            'inventory.counts.authorize_above_threshold',
        ];

        return array_values(array_diff(PermissionCatalog::names(), $excluidos));
    }
}
