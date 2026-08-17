<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use App\Modules\Tenancy\Infrastructure\Models\TenantLimit;

/**
 * Medición y verificación de los límites del tenant (D4).
 *
 * Regla que este servicio hace cumplir: **ninguna lógica de negocio consulta "el plan"**.
 * Consulta un límite concreto y un uso medido (ESPECIFICACIÓN_MAESTRA §2).
 *
 * ## El uso se MIDE, no se almacena
 *
 * `max_users` se compara contra un `COUNT` de membresías activas, no contra un contador.
 * Mismo principio que los cortes calculados de ADR-004 y por la misma razón: un contador se
 * desincroniza —una baja que no lo decrementa, una alta dentro de una transacción revertida—
 * y entonces hay dos verdades y ninguna forma de saber cuál miente.
 *
 * El costo es un `COUNT` por verificación, sobre decenas de filas y con índice
 * `(tenant_id, status)`. Es barato justo donde importa.
 *
 * `limit_value` NULL significa **sin límite**, no cero.
 */
final class TenantLimits
{
    /**
     * ¿Cabe una unidad más dentro del límite?
     */
    public function allows(TenantLimitKey $key): bool
    {
        $limit = TenantLimit::query()->where('limit_key', $key->value)->first();

        // Sin fila no hay límite configurado: el tenant es ilimitado en esa dimensión hasta
        // que el super admin diga lo contrario. Es más seguro que asumir cero, que dejaría a
        // un tenant nuevo sin poder crear nada.
        if ($limit === null) {
            return true;
        }

        return $limit->allows($this->currentUsage($key));
    }

    /**
     * Uso actual, medido.
     */
    public function currentUsage(TenantLimitKey $key): int
    {
        return match ($key) {
            // Sólo las membresías que pueden operar. Una baja libera plaza de inmediato; una
            // invitación sin aceptar todavía no la ocupa.
            TenantLimitKey::MaxUsers => TenantMembership::query()
                ->where('status', MembershipStatus::Active->value)
                ->count(),

            TenantLimitKey::MaxBranches => Branch::query()
                ->where('status', OperationalStatus::Active->value)
                ->count(),

            // Un almacén NO cuenta como sucursal para el cobro (§2): son dos límites distintos.
            TenantLimitKey::MaxWarehouses => Warehouse::query()
                ->where('status', OperationalStatus::Active->value)
                ->count(),

            TenantLimitKey::MaxTerminalsPerBranch => 0,
        };
    }

    /**
     * Valor del límite, o null si es ilimitado.
     */
    public function limit(TenantLimitKey $key): ?int
    {
        return TenantLimit::query()
            ->where('limit_key', $key->value)
            ->first()
            ?->limit_value;
    }
}
