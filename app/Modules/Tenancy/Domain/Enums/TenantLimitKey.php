<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

/**
 * Catálogo cerrado de límites medibles del tenant (D4).
 *
 * Regla que este enum hace cumplir: **ninguna lógica de negocio consulta "el
 * plan"**. Consulta un límite concreto y los módulos activos
 * (ESPECIFICACIÓN_MAESTRA §2).
 *
 * El USO se mide, no se almacena: `MaxUsers` se compara contra un `COUNT` de
 * membresías activas, no contra un contador. Mismo principio que los cortes
 * calculados de ADR-004 y por la misma razón: un contador se desincroniza y
 * entonces hay dos verdades y ninguna forma de saber cuál miente.
 *
 * `limit_value` NULL significa **sin límite**, no cero.
 */
enum TenantLimitKey: string
{
    /** Membresías activas. Un almacén no cuenta como usuario, obviamente. */
    case MaxUsers = 'max_users';

    /** Sucursales activas. Es la unidad que se cobra (§2). */
    case MaxBranches = 'max_branches';

    /** Almacenes activos. Un almacén NO cuenta como sucursal para el cobro (§2). */
    case MaxWarehouses = 'max_warehouses';

    /** Terminales por sucursal. */
    case MaxTerminalsPerBranch = 'max_terminals_per_branch';

    public function label(): string
    {
        return match ($this) {
            self::MaxUsers => 'Usuarios',
            self::MaxBranches => 'Sucursales',
            self::MaxWarehouses => 'Almacenes',
            self::MaxTerminalsPerBranch => 'Terminales por sucursal',
        };
    }
}
