<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * CATÁLOGO CERRADO DE PERMISOS DEL SISTEMA (D10).
 *
 * El tenant combina permisos en roles; **no inventa permisos**. Esta clase es la
 * fuente de verdad y el seeder sólo la aplica.
 *
 * Nomenclatura: `{módulo}.{recurso}.{acción}`. El prefijo no es decorativo: de él se
 * deduce el módulo dueño para ocultar los permisos de módulos que el tenant no tiene
 * contratados (§4.2) y para el corte que hace `ModuleGate` antes de mirar el rol.
 *
 * Wildcards deshabilitados en `config/permission.php`: un catálogo cerrado que
 * admitiera comodines dejaría de ser cerrado.
 *
 * ## Se siembra completo desde la Iteración 1 (D72)
 *
 * Aunque la mayoría de los módulos se construyan entre la Iteración 2 y la 9. Es un
 * catálogo del sistema, cuesta un `INSERT`, y permite armar los roles plantilla
 * completos. Los permisos de cada módulo **se afinan al llegar su iteración** y ese
 * ajuste se registra como decisión; un permiso retirado se elimina del catálogo y de
 * los roles que lo tuvieran en la misma migración.
 */
final class PermissionCatalog
{
    /**
     * Módulo dueño => [nombre del permiso => descripción que ve el tenant].
     *
     * La descripción es obligatoria y va en español: es el texto que lee quien arma un
     * rol, y un permiso sin explicación es un permiso que alguien marcará sin
     * entenderlo.
     *
     * @return array<string, array<string, string>>
     */
    public static function definitions(): array
    {
        return [
            // ================= SHARED KERNEL =================
            'Tenancy' => [
                'tenancy.subscription.view' => 'Ver la suscripción y sus límites',
                'tenancy.modules.view' => 'Ver los módulos contratados',
            ],

            'Identity' => [
                'identity.users.view' => 'Ver el personal',
                'identity.users.create' => 'Dar de alta personal',
                'identity.users.update' => 'Editar datos del personal',
                'identity.users.suspend' => 'Suspender o dar de baja personal',
                'identity.memberships.assign_roles' => 'Asignar roles al personal',
                'identity.memberships.manage_branch_scopes' => 'Definir en qué sucursales opera cada persona',
                'identity.memberships.reset_pin' => 'Restablecer el PIN de terminal',
                'identity.roles.view' => 'Ver los roles',
                'identity.roles.create' => 'Crear roles',
                'identity.roles.update' => 'Editar roles y sus permisos',
                'identity.roles.delete' => 'Eliminar roles',
                'identity.employee_profiles.view' => 'Ver perfiles de empleado',
                'identity.employee_profiles.manage' => 'Editar perfiles de empleado',
                'identity.employee_profiles.view_sensitive' => 'Ver CURP, RFC y NSS del personal',
            ],

            'Organization' => [
                'organization.branches.view' => 'Ver sucursales',
                'organization.branches.manage' => 'Crear y editar sucursales',
                'organization.warehouses.view' => 'Ver almacenes',
                'organization.warehouses.manage' => 'Crear y editar almacenes',
                'organization.preparation_areas.view' => 'Ver áreas de preparación',
                'organization.preparation_areas.manage' => 'Crear y editar áreas de preparación',
                'organization.terminals.view' => 'Ver terminales',
                'organization.terminals.manage' => 'Crear y editar terminales',

                // Las impresoras son hardware de la sucursal, igual que la terminal y el almacén, y por eso su
                // permiso vive con ellos y no en `printing.*` — ése gobierna los TRABAJOS de impresión, que es otra
                // cosa: quien reimprime un ticket no tiene por qué poder cambiar la IP de la cocina.
                'organization.printers.view' => 'Ver impresoras',
                'organization.printers.manage' => 'Crear y editar impresoras',
            ],

            'Configuration' => [
                'configuration.tenant.view' => 'Ver la configuración del negocio',
                'configuration.tenant.update' => 'Cambiar la configuración del negocio',
                'configuration.branch.view' => 'Ver la configuración de una sucursal',
                'configuration.branch.update' => 'Cambiar la configuración de una sucursal',
            ],

            'Audit' => [
                'audit.entries.view' => 'Consultar la bitácora de auditoría',
                'audit.entries.export' => 'Exportar la bitácora de auditoría',
            ],

            'Notifications' => [
                'notifications.preferences.manage' => 'Configurar sus notificaciones',
            ],

            // ================= DOMINIO =================
            'Catalog' => [
                'catalog.articles.view' => 'Ver artículos',
                'catalog.articles.manage' => 'Crear y editar artículos',
                'catalog.articles.archive' => 'Archivar artículos',
                'catalog.prices.view' => 'Ver precios',
                'catalog.prices.update' => 'Cambiar precios',
                'catalog.prices.history.view' => 'Ver el historial de precios',
                'catalog.categories.manage' => 'Administrar categorías',
                'catalog.tags.manage' => 'Administrar etiquetas',
                'catalog.units.manage' => 'Administrar unidades y conversiones',
                'catalog.modifiers.manage' => 'Administrar modificadores',
            ],

            'Costing' => [
                'costing.recipes.view' => 'Ver recetas',
                'costing.recipes.manage' => 'Crear y editar recetas',
                'costing.costs.view' => 'Ver costos',
                'costing.costs.update' => 'Capturar costos',
                'costing.costs.history.view' => 'Ver el historial de costos',
                'costing.suggested_prices.view' => 'Ver precios sugeridos y márgenes',
            ],

            'Inventory' => [
                'inventory.stock.view' => 'Ver existencias',
                'inventory.kardex.view' => 'Consultar el kardex',
                'inventory.entries.create' => 'Registrar entradas de inventario',
                'inventory.exits.create' => 'Registrar salidas de inventario',
                'inventory.adjustments.create' => 'Registrar ajustes de inventario',
                'inventory.counts.create' => 'Iniciar conteos físicos',
                'inventory.counts.close' => 'Cerrar conteos y aplicar diferencias',
                'inventory.counts.authorize_above_threshold' => 'Autorizar cierres de conteo sobre el umbral',
                'inventory.production.create' => 'Registrar producciones',
                'inventory.waste.create' => 'Registrar mermas',
                'inventory.waste.authorize_above_threshold' => 'Autorizar mermas sobre el umbral',
                'inventory.transfers.request' => 'Solicitar transferencias',
                'inventory.transfers.authorize' => 'Autorizar transferencias',
                'inventory.transfers.prepare' => 'Preparar transferencias',
                'inventory.transfers.ship' => 'Enviar transferencias',
                'inventory.transfers.receive' => 'Recibir transferencias',
                'inventory.lots.manage' => 'Administrar lotes y caducidades',
            ],

            'Purchasing' => [
                'purchasing.suppliers.view' => 'Ver proveedores',
                'purchasing.suppliers.manage' => 'Crear y editar proveedores',
                'purchasing.receipts.create' => 'Registrar recepciones de compra',
                'purchasing.receipts.confirm' => 'Confirmar recepciones y aplicarlas al inventario',
                'purchasing.supplier_prices.view' => 'Consultar precios de proveedores',
            ],

            'Finance' => [
                'finance.journal.view' => 'Consultar el diario financiero',
                'finance.cuts.view' => 'Ver cortes de caja',
                'finance.cuts.close' => 'Cerrar cortes de caja',
                'finance.expenses.create_from_cash' => 'Registrar gastos desde caja',
                'finance.expenses.create_outside_cash' => 'Registrar gastos fuera de caja',
                'finance.expenses.authorize_above_threshold' => 'Autorizar gastos sobre el umbral',
                'finance.deposits.create' => 'Registrar depósitos bancarios',
                'finance.tips.settle' => 'Liquidar propinas',
                'finance.customer_credit.view' => 'Consultar crédito de clientes',
                'finance.customer_credit.manage' => 'Administrar límites y abonos de crédito',
            ],

            'Customers' => [
                'customers.customers.view' => 'Ver clientes',
                'customers.customers.manage' => 'Crear y editar clientes',
                'customers.fiscal_profiles.manage' => 'Administrar perfiles fiscales',
                'customers.addresses.manage' => 'Administrar direcciones de clientes',
            ],

            // ================= OPERACIONES =================
            'Pos' => [
                'pos.orders.create' => 'Capturar órdenes',
                'pos.orders.send_to_area' => 'Comandar a las áreas de preparación',
                'pos.items.cancel_uncommanded' => 'Cancelar items no comandados',
                'pos.items.cancel_commanded' => 'Cancelar items ya comandados',
                'pos.accounts.request_bill' => 'Solicitar la cuenta',
                'pos.accounts.charge' => 'Cobrar una cuenta',
                'pos.accounts.split' => 'Dividir cuentas',
                'pos.accounts.move_items' => 'Mover items entre cuentas',
                'pos.accounts.merge' => 'Juntar cuentas',
                'pos.accounts.reopen' => 'Reabrir una cuenta cerrada',
                'pos.discounts.apply_item' => 'Aplicar descuento a un item',
                'pos.discounts.apply_account' => 'Aplicar descuento a una cuenta',
                'pos.discounts.courtesy' => 'Registrar cortesías',
                'pos.sessions.open' => 'Abrir sesión de caja',
                'pos.sessions.precount' => 'Realizar el precorte',
                'pos.sessions.close' => 'Cerrar sesión de caja',
                'pos.sessions.withdraw' => 'Realizar retiros parciales de caja',
                'pos.cash_drawer.open' => 'Abrir el cajón de dinero',
                'pos.takeout.manage' => 'Administrar pedidos para llevar',
                'pos.credit.charge_to_customer' => 'Cargar una cuenta al crédito del cliente',
            ],

            'Printing' => [
                'printing.jobs.view' => 'Ver trabajos de impresión',
                'printing.jobs.reprint' => 'Reimprimir comandas y tickets',
                'printing.jobs.retry' => 'Reintentar trabajos fallidos',
            ],

            'Floor' => [
                'floor.layouts.view' => 'Ver el piso de venta',
                'floor.layouts.edit' => 'Editar planos y zonas de mesas',
                'floor.tables.join' => 'Unir y separar mesas',
            ],

            'Promotions' => [
                'promotions.promotions.view' => 'Ver promociones',
                'promotions.promotions.manage' => 'Crear y editar promociones',
                'promotions.coupons.manage' => 'Administrar cupones',
            ],

            // ================= ANALÍTICA =================
            'Reporting' => [
                'reporting.exports.create' => 'Exportar reportes a PDF y Excel',
                'reporting.schedules.manage' => 'Programar reportes',
                'reporting.saved_views.manage' => 'Guardar vistas de reportes',
            ],

            'Dashboards' => [
                'dashboards.dashboards.view' => 'Ver tableros',
                'dashboards.dashboards.manage' => 'Crear y editar tableros',
                'dashboards.dashboards.publish' => 'Publicar tableros a roles o sucursales',
                'dashboards.goals.manage' => 'Definir metas por sucursal y periodo',
            ],

            // ================= ACTIVABLES POR TENANT =================
            'DigitalMenus' => [
                'digital_menus.menus.manage' => 'Administrar el menú digital',
                'digital_menus.pdf.generate' => 'Generar el menú en PDF',
            ],

            'Ecommerce' => [
                'ecommerce.store.configure' => 'Configurar la tienda en línea',
                'ecommerce.orders.view' => 'Ver pedidos de la tienda',
                'ecommerce.orders.accept' => 'Aceptar pedidos de la tienda',
                'ecommerce.orders.reject' => 'Rechazar pedidos de la tienda',
                'ecommerce.gateways.configure' => 'Configurar la pasarela de pago',
                'ecommerce.coupons.manage' => 'Administrar cupones de la tienda',
                'ecommerce.shipping_zones.manage' => 'Administrar zonas de entrega',
            ],
        ];
    }

    /**
     * Todos los nombres de permiso del catálogo.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (self::definitions() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Permisos de un módulo.
     *
     * @return list<string>
     */
    public static function forModule(string $module): array
    {
        return array_keys(self::definitions()[$module] ?? []);
    }

    /**
     * Permisos de los módulos indicados, aplanados.
     *
     * @param  list<string>  $modules
     * @return list<string>
     */
    public static function forModules(array $modules): array
    {
        $names = [];

        foreach ($modules as $module) {
            $names = [...$names, ...self::forModule($module)];
        }

        return $names;
    }

    public static function count(): int
    {
        return count(self::names());
    }
}
