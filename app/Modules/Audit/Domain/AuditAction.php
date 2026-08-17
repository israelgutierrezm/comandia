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
