<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * Acciones auditables del shared kernel (diseño §6.2).
 *
 * `action` sale de un catálogo de constantes por el mismo motivo que los permisos: un
 * `action` escrito a mano produce un evento que ningún reporte encuentra. El reporte de
 * descuentos y cancelaciones que §9 exige como mitigación del robo hormiga filtra por
 * estos valores exactos.
 *
 * ## Por qué constantes por módulo y no un enum central
 *
 * Un enum único en `Audit` tendría que editarse cada vez que otro módulo añade una acción,
 * y eso convertiría al módulo de auditoría en dependiente de todos los demás — lo contrario
 * de la regla de dependencias de ARQUITECTURA_MAESTRA §2.
 *
 * Así que el catálogo está distribuido: cada módulo declara sus acciones en su propia clase
 * (`Pos\Domain\PosAuditAction`, etc.). La contrapartida honesta es que nada impide
 * técnicamente escribir una cadena literal; el acuerdo es que no se hace, y lo que lo
 * respalda es que un reporte con un `action` inventado sale vacío y se nota.
 */
final class AuditAction
{
    // ---- Acceso ----
    public const LOGIN = 'auth.login';

    public const LOGIN_FAILED = 'auth.login_failed';

    public const LOGOUT = 'auth.logout';

    public const TWO_FACTOR_ENABLED = 'auth.two_factor_enabled';

    // ---- Autorización por PIN (ADR-008) ----
    public const PIN_AUTHORIZATION_GRANTED = 'auth.pin_authorization_granted';

    public const PIN_AUTHORIZATION_DENIED = 'auth.pin_authorization_denied';

    public const PIN_LOCKED = 'auth.pin_locked';

    // ---- Contexto operativo ----
    public const ROLE_SWITCHED = 'context.role_switched';

    public const BRANCH_SWITCHED = 'context.branch_switched';

    // ---- Identidad ----
    public const USER_CREATED = 'identity.user_created';

    public const USER_SUSPENDED = 'identity.user_suspended';

    public const ROLES_ASSIGNED = 'identity.roles_assigned';

    public const BRANCH_SCOPES_UPDATED = 'identity.branch_scopes_updated';

    public const PIN_RESET = 'identity.pin_reset';

    public const ROLE_CREATED = 'identity.role_created';

    public const ROLE_UPDATED = 'identity.role_updated';

    public const ROLE_DELETED = 'identity.role_deleted';

    public const SENSITIVE_PROFILE_VIEWED = 'identity.sensitive_profile_viewed';

    // ---- Organización ----
    public const BRANCH_CREATED = 'organization.branch_created';

    public const BRANCH_UPDATED = 'organization.branch_updated';

    public const WAREHOUSE_CREATED = 'organization.warehouse_created';

    public const WAREHOUSE_UPDATED = 'organization.warehouse_updated';

    public const PREPARATION_AREA_CREATED = 'organization.preparation_area_created';

    public const PREPARATION_AREA_UPDATED = 'organization.preparation_area_updated';

    public const TERMINAL_CREATED = 'organization.terminal_created';

    public const TERMINAL_UPDATED = 'organization.terminal_updated';

    public const PRINTER_CREATED = 'organization.printer_created';

    public const PRINTER_UPDATED = 'organization.printer_updated';

    // ---- Finanzas ----

    public const PAYMENT_METHOD_CREATED = 'finance.payment_method_created';

    public const PAYMENT_METHOD_UPDATED = 'finance.payment_method_updated';

    public const EXPENSE_CATEGORY_CREATED = 'finance.expense_category_created';

    public const EXPENSE_CATEGORY_UPDATED = 'finance.expense_category_updated';

    // ---- Salón ----

    public const FLOOR_PLAN_CREATED = 'floor.plan_created';

    public const FLOOR_PLAN_UPDATED = 'floor.plan_updated';

    // El guardado por LOTE del editor es un solo asiento y no uno por mesa: mover doce mesas es un acto, y doce
    // asientos idénticos con distinto ULID harían ilegible la bitácora del día que alguien reacomodó el salón.
    public const FLOOR_LAYOUT_SAVED = 'floor.layout_saved';

    public const FLOOR_ZONE_CREATED = 'floor.zone_created';

    public const FLOOR_ZONE_UPDATED = 'floor.zone_updated';

    public const FLOOR_ZONE_DELETED = 'floor.zone_deleted';

    public const TABLE_CREATED = 'floor.table_created';

    public const TABLE_UPDATED = 'floor.table_updated';

    public const TABLE_ARCHIVED = 'floor.table_archived';

    public const TABLE_RESTORED = 'floor.table_restored';

    public const TABLES_JOINED = 'floor.tables_joined';

    public const TABLES_SEPARATED = 'floor.tables_separated';

    // ---- Punto de venta: caja ----

    public const CASH_SESSION_OPENED = 'pos.cash_session_opened';

    public const CASH_SESSION_PRECOUNTED = 'pos.cash_session_precounted';

    public const CASH_SESSION_DECLARED = 'pos.cash_session_declared';

    public const CASH_SESSION_CLOSED = 'pos.cash_session_closed';

    public const CASH_WITHDRAWAL_REGISTERED = 'pos.cash_withdrawal_registered';

    // ---- Punto de venta: cuentas ----

    public const POS_ACCOUNT_OPENED = 'pos.account_opened';

    public const POS_ORDER_CAPTURED = 'pos.order_captured';

    public const POS_ACCOUNT_BILL_REQUESTED = 'pos.account_bill_requested';

    public const POS_ACCOUNT_CLOSED = 'pos.account_closed';

    public const POS_ACCOUNT_REOPENED = 'pos.account_reopened';

    public const POS_ACCOUNT_CANCELLED = 'pos.account_cancelled';

    public const POS_ORDER_COMMANDED = 'pos.order_commanded';

    public const POS_ACCOUNT_CHARGED = 'pos.account_charged';

    /**
     * Descuento o cortesía.
     *
     * §9 lo nombra explícitamente entre las mitigaciones del robo hormiga: el reporte filtra por este valor y agrupa por
     * autorizador. Guarda a las dos personas —quien lo aplicó y quien lo autorizó— porque el patrón que se busca es «el
     * mismo mesero pidiendo autorización veinte veces por turno».
     */
    public const POS_DISCOUNT_APPLIED = 'pos.discount_applied';

    public const POS_ACCOUNT_SPLIT = 'pos.account_split';

    /**
     * Items movidos entre cuentas.
     *
     * Es el asiento que cierra «el hueco del bar»: sin él, mover un item a otra cuenta que después se cancela es
     * indistinguible de haberlo capturado allí desde el principio.
     */
    public const POS_ITEMS_MOVED = 'pos.items_moved';

    public const POS_ACCOUNTS_MERGED = 'pos.accounts_merged';

    public const POS_ACCOUNT_MOVED_TABLE = 'pos.account_moved_table';

    public const POS_TAKEOUT_DELIVERY_CHANGED = 'pos.takeout_delivery_changed';

    public const POS_ACCOUNT_CUSTOMER_SET = 'pos.account_customer_set';

    /**
     * Cancelación de items YA COMANDADOS.
     *
     * Es una de las acciones que §9 nombra explícitamente como mitigación del robo hormiga: el reporte de descuentos y
     * cancelaciones filtra por este valor exacto. Cancelar algo NO comandado no llega aquí, porque no ocurrió nada.
     */
    public const POS_ITEMS_CANCELLED = 'pos.items_cancelled';

    /**
     * Se borró un item que nadie había preparado.
     *
     * Sí se audita, aunque §6.3 diga que «no hay rastro»: lo que no queda es rastro **en la cuenta** —la línea
     * desaparece— y eso es correcto porque no se cobró nada. Pero que alguien borre veinte líneas en un turno es un
     * patrón que el negocio querrá poder ver, y sin este asiento no habría dónde verlo.
     */
    public const POS_ITEMS_DELETED = 'pos.items_deleted';

    public const POS_TICKET_REPRINTED = 'pos.ticket_reprinted';

    public const POS_AREA_ROUTE_CREATED = 'pos.area_route_created';

    public const POS_AREA_ROUTE_DELETED = 'pos.area_route_deleted';

    // ---- Impresión ----

    /**
     * Se abrió el cajón de dinero fuera de un cobro.
     *
     * Es de los asientos que más importan de todo el sistema: un cajón abierto sin venta es dinero al alcance sin ningún
     * documento que lo explique. Guarda quién lo pidió, quién lo autorizó con su PIN y por qué.
     */
    public const CASH_DRAWER_OPENED = 'printing.cash_drawer_opened';

    public const PRINT_JOB_RETRIED = 'printing.job_retried';

    public const PRINT_AGENT_CREATED = 'printing.agent_created';

    public const PRINT_AGENT_TOKEN_ROTATED = 'printing.agent_token_rotated';

    public const PRINT_AGENT_ARCHIVED = 'printing.agent_archived';

    // ---- Finanzas ----

    /**
     * Un gasto registrado.
     *
     * Guarda a las dos personas —quien lo registró y quien lo autorizó por encima del umbral— por la misma razón que los
     * descuentos: el patrón que importa es quién pide autorización y con qué frecuencia.
     */
    public const EXPENSE_REGISTERED = 'finance.expense_registered';

    public const BANK_DEPOSIT_REGISTERED = 'finance.bank_deposit_registered';

    /**
     * Propina entregada.
     *
     * Guarda a las dos personas: a quién se le pagó y quién se lo entregó. Es dinero que sale del cajón hacia el
     * bolsillo de alguien, y «quién entregó» es la mitad de la evidencia.
     */
    public const TIPS_SETTLED = 'finance.tips_settled';

    // ---- Clientes y crédito ----

    public const CUSTOMER_CREATED = 'customers.customer_created';

    public const CUSTOMER_CREDIT_UPDATED = 'customers.credit_updated';

    public const CUSTOMER_CREDIT_REPAID = 'customers.credit_repaid';

    // ---- Catálogo y precios ----
    //
    // §6.7 lista los precios entre lo que la bitácora técnica vigila, junto con accesos,
    // configuración, usuarios y roles y las acciones sensibles del POS. Un cambio de precio deja
    // además su historial de dominio propio (`price_changes`), que es inmutable; las dos capas de
    // auditoría de §6.7 son complementarias y no alternativas.
    public const PRICE_CHANGED = 'catalog.price_changed';

    // ---- Inventarios ----

    /**
     * Se registró una merma (D27).
     *
     * De todos los movimientos de inventario, la merma es el único que va a la bitácora TÉCNICA además del
     * kardex. Razón: §6.7 lista las acciones que la bitácora vigila, y una merma es una **pérdida** con actor —
     * la zona de robo hormiga que §9 pide poder investigar. El resto de los movimientos tiene su propia
     * evidencia inmutable en el kardex y registrarlos aquí produciría una bitácora que nadie puede leer.
     */
    public const WASTE_REGISTERED = 'inventory.waste_registered';

    /**
     * Se cerró un conteo físico y se aplicaron sus diferencias (D24).
     *
     * Por la misma razón que la merma, y con más peso: es la única operación del sistema que reescribe cientos de
     * saldos de una vez. El asiento guarda las dos cifras del cierre —el neto y el bruto— y quién autorizó, que es
     * lo que permite contestar «¿quién firmó que faltaran cincuenta mil pesos de mercancía?».
     */
    public const STOCK_COUNT_CLOSED = 'inventory.stock_count_closed';

    // ---- Configuración ----
    public const SETTING_UPDATED = 'configuration.setting_updated';

    // ---- Tenancy (acciones del super admin sobre un tenant) ----
    public const TENANT_STATUS_CHANGED = 'tenancy.status_changed';

    public const TENANT_MODULE_ENABLED = 'tenancy.module_enabled';

    public const TENANT_MODULE_DISABLED = 'tenancy.module_disabled';

    public const TENANT_LIMITS_UPDATED = 'tenancy.limits_updated';

    /**
     * Texto en español de cada acción, para la pantalla de auditoría.
     *
     * Las etiquetas viven JUNTO a las constantes y no en un mapa aparte —ni en el frontend— por lo
     * mismo que el catálogo está distribuido: cuando `Pos` declare sus acciones, traerá sus
     * etiquetas con ellas y nadie tendrá que editar este archivo. Un mapa en Vue habría obligado a
     * tocar el frontend por cada acción nueva, y la pantalla mostraba `organization.branch_created`
     * en crudo justamente porque no existía este método.
     *
     * El identificador NO se traduce: es el valor por el que filtran los reportes y tiene que
     * seguir siendo estable e inglés.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::LOGIN => 'Inició sesión',
            self::LOGIN_FAILED => 'Intento de sesión fallido',
            self::LOGOUT => 'Cerró sesión',
            self::TWO_FACTOR_ENABLED => 'Activó segundo factor',

            self::PIN_AUTHORIZATION_GRANTED => 'Autorizó con PIN',
            self::PIN_AUTHORIZATION_DENIED => 'PIN rechazado',
            self::PIN_LOCKED => 'PIN bloqueado por intentos',

            self::ROLE_SWITCHED => 'Cambió de rol activo',
            self::BRANCH_SWITCHED => 'Cambió de negocio o sucursal',

            self::USER_CREATED => 'Alta de persona',
            self::USER_SUSPENDED => 'Suspendió a una persona',
            self::ROLES_ASSIGNED => 'Asignó roles',
            self::BRANCH_SCOPES_UPDATED => 'Cambió las sucursales donde opera una persona',
            self::PIN_RESET => 'Restableció un PIN',
            self::ROLE_CREATED => 'Creó un rol',
            self::ROLE_UPDATED => 'Modificó un rol',
            self::ROLE_DELETED => 'Eliminó un rol',
            self::SENSITIVE_PROFILE_VIEWED => 'Consultó datos sensibles de personal',

            self::BRANCH_CREATED => 'Creó una sucursal',
            self::BRANCH_UPDATED => 'Modificó una sucursal',
            self::WAREHOUSE_CREATED => 'Creó un almacén',
            self::WAREHOUSE_UPDATED => 'Modificó un almacén',
            self::PREPARATION_AREA_CREATED => 'Creó un área de preparación',
            self::PREPARATION_AREA_UPDATED => 'Modificó un área de preparación',
            self::TERMINAL_CREATED => 'Creó una terminal',
            self::TERMINAL_UPDATED => 'Modificó una terminal',
            self::PRINTER_CREATED => 'Creó una impresora',
            self::PRINTER_UPDATED => 'Modificó una impresora',
            self::PAYMENT_METHOD_CREATED => 'Creó un método de pago',
            self::PAYMENT_METHOD_UPDATED => 'Modificó un método de pago',
            self::EXPENSE_CATEGORY_CREATED => 'Creó una categoría de gasto',
            self::EXPENSE_CATEGORY_UPDATED => 'Modificó una categoría de gasto',
            self::FLOOR_PLAN_CREATED => 'Creó un plano de salón',
            self::FLOOR_PLAN_UPDATED => 'Modificó un plano de salón',
            self::FLOOR_LAYOUT_SAVED => 'Reacomodó el salón',
            self::FLOOR_ZONE_CREATED => 'Creó una zona del salón',
            self::FLOOR_ZONE_UPDATED => 'Modificó una zona del salón',
            self::FLOOR_ZONE_DELETED => 'Eliminó una zona del salón',
            self::TABLE_CREATED => 'Creó una mesa',
            self::TABLE_UPDATED => 'Modificó una mesa',
            self::TABLE_ARCHIVED => 'Retiró una mesa del piso',
            self::TABLE_RESTORED => 'Devolvió una mesa al piso',
            self::TABLES_JOINED => 'Unió mesas',
            self::TABLES_SEPARATED => 'Separó mesas',
            self::CASH_SESSION_OPENED => 'Abrió una caja',
            self::CASH_SESSION_PRECOUNTED => 'Hizo el precorte de una caja',
            self::CASH_SESSION_DECLARED => 'Declaró el efectivo de una caja',
            self::CASH_SESSION_CLOSED => 'Cerró una caja',
            self::CASH_WITHDRAWAL_REGISTERED => 'Retiró efectivo de una caja',
            self::POS_ACCOUNT_OPENED => 'Abrió una cuenta',
            self::POS_ORDER_CAPTURED => 'Capturó una orden',
            self::POS_ACCOUNT_BILL_REQUESTED => 'Solicitó la cuenta',
            self::POS_ACCOUNT_CLOSED => 'Cerró una cuenta',
            self::POS_ACCOUNT_REOPENED => 'Reabrió una cuenta',
            self::POS_ACCOUNT_CANCELLED => 'Canceló una cuenta',
            self::POS_ORDER_COMMANDED => 'Comandó una orden',
            self::POS_ACCOUNT_CHARGED => 'Cobró una cuenta',
            self::POS_DISCOUNT_APPLIED => 'Aplicó un descuento o cortesía',
            self::POS_ACCOUNT_SPLIT => 'Dividió una cuenta',
            self::POS_ITEMS_MOVED => 'Movió items entre cuentas',
            self::POS_ACCOUNTS_MERGED => 'Juntó dos cuentas',
            self::POS_ACCOUNT_MOVED_TABLE => 'Movió una cuenta de mesa',
            self::POS_TAKEOUT_DELIVERY_CHANGED => 'Cambió el estado de entrega de un pedido para llevar',
            self::POS_ACCOUNT_CUSTOMER_SET => 'Identificó una cuenta con un cliente',
            self::POS_ITEMS_CANCELLED => 'Canceló items ya comandados',
            self::POS_ITEMS_DELETED => 'Borró items sin comandar',
            self::POS_TICKET_REPRINTED => 'Reimprimió un ticket',
            self::POS_AREA_ROUTE_CREATED => 'Creó una regla de ruteo a un área',
            self::POS_AREA_ROUTE_DELETED => 'Borró una regla de ruteo a un área',
            self::CASH_DRAWER_OPENED => 'Abrió el cajón de dinero',
            self::EXPENSE_REGISTERED => 'Registró un gasto',
            self::BANK_DEPOSIT_REGISTERED => 'Registró un depósito bancario',
            self::TIPS_SETTLED => 'Liquidó propinas',
            self::CUSTOMER_CREATED => 'Dio de alta un cliente',
            self::CUSTOMER_CREDIT_UPDATED => 'Cambió el crédito de un cliente',
            self::CUSTOMER_CREDIT_REPAID => 'Registró un abono de crédito',
            self::PRINT_JOB_RETRIED => 'Reintentó un trabajo de impresión',
            self::PRINT_AGENT_CREATED => 'Dio de alta un agente de impresión',
            self::PRINT_AGENT_TOKEN_ROTATED => 'Rotó el token de un agente de impresión',
            self::PRINT_AGENT_ARCHIVED => 'Archivó un agente de impresión',

            self::PRICE_CHANGED => 'Cambió un precio',

            self::WASTE_REGISTERED => 'Registró una merma',
            self::STOCK_COUNT_CLOSED => 'Cerró un conteo físico',

            self::SETTING_UPDATED => 'Cambió una configuración',

            self::TENANT_STATUS_CHANGED => 'Cambió el estado del negocio',
            self::TENANT_MODULE_ENABLED => 'Contrató un módulo',
            self::TENANT_MODULE_DISABLED => 'Canceló un módulo',
            self::TENANT_LIMITS_UPDATED => 'Cambió los límites del plan',
        ];
    }

    /**
     * Cae al identificador cuando no hay etiqueta: un módulo que declare una acción nueva sin
     * etiqueta se ve raro en pantalla, y eso es preferible a una fila vacía o una excepción sobre
     * datos inmutables ya escritos.
     */
    public static function label(string $action): string
    {
        return self::labels()[$action] ?? $action;
    }

    /**
     * Acciones de acceso, para el reporte de intentos fallidos.
     *
     * @return list<string>
     */
    public static function authActions(): array
    {
        return [
            self::LOGIN,
            self::LOGIN_FAILED,
            self::LOGOUT,
            self::PIN_AUTHORIZATION_GRANTED,
            self::PIN_AUTHORIZATION_DENIED,
            self::PIN_LOCKED,
        ];
    }
}
