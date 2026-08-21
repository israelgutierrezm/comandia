<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Authorization;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Quién puede escuchar qué canal privado.
 *
 * ## Por qué es una clase y no una función en `channels.php`
 *
 * Porque una función global se **redeclara**. `routes/channels.php` se carga cada vez que la aplicación arranca, y en
 * una suite eso pasa una vez por prueba: la segunda lanza `Cannot redeclare`, que no es una prueba en rojo sino la
 * **suite completa abortada** antes de ejecutar nada. Es exactamente el fallo que `TestHelperNamesAreUniqueTest`
 * vigila desde la Iteración 3, y lo encontré cometiéndolo aquí.
 *
 * ## Un canal se pide con el ULID que manda el CLIENTE
 *
 * Es el hueco que D292 cerró en once endpoints, en una superficie nueva y sin costumbre: quien se suscribe dice a qué
 * sucursal quiere oír. Sin comprobar el alcance, cualquiera con sesión oiría el piso de cualquier sucursal de su
 * negocio — y el `tenant_id` no protege de eso, porque la sucursal ajena es del **mismo** negocio.
 *
 * ## Las tres comprobaciones, en este orden
 *
 * 1. **La sucursal es del tenant del canal.** Sin esto el nombre del canal sería una mentira: se podría pedir
 *    `tenant.A.branch.{sucursal de B}` y el segundo tramo mandaría sobre el primero.
 * 2. **Quien pide tiene alcance a esa sucursal** (`canOperateInBranch`).
 * 3. **Tiene el permiso, por el servicio de contexto** — nunca `$user->can()` directo: Spatie SUMA los permisos de
 *    todos los roles y aquí manda el rol ACTIVO (D9). Un mesero que además es gerente no debe oír la cocina mientras
 *    atiende mesas.
 *
 * Devuelve `true` o `false` y nunca lanza: un canal denegado es una suscripción rechazada, no un error del servidor.
 */
final readonly class ChannelAccess
{
    public function __construct(
        private ContextHolder $holder,
        private Authorize $authorize,
    ) {}

    public function branch(string $tenantUlid, string $branchUlid, string $permission): bool
    {
        $branch = $this->resolveBranch($tenantUlid, $branchUlid);

        if ($branch === null) {
            return false;
        }

        $membership = $this->holder->getOrNull()?->membership;

        if ($membership === null || ! $membership->canOperateInBranch((int) $branch->id)) {
            return false;
        }

        // La misma comprobación que hace el endpoint HTTP que sirve esta pantalla, y tiene que decir lo mismo: dos
        // respuestas distintas a la misma pregunta serían un agujero por el lado que autoriza de más.
        return $this->authorize->allows($permission, (int) $branch->id);
    }

    public function area(string $tenantUlid, string $branchUlid, string $areaUlid, string $permission): bool
    {
        if (! $this->branch($tenantUlid, $branchUlid, $permission)) {
            return false;
        }

        $branch = $this->resolveBranch($tenantUlid, $branchUlid);
        $area = PreparationArea::query()->where('ulid', $areaUlid)->first();

        // El área tiene que ser DE ESA sucursal, o la cocina de Polanco recibiría las comandas de Roma Norte — y las
        // dos son del mismo negocio, así que nada más lo impediría.
        return $branch !== null
            && $area !== null
            && (int) $area->branch_id === (int) $branch->id;
    }

    private function resolveBranch(string $tenantUlid, string $branchUlid): ?Branch
    {
        $contexto = $this->holder->getOrNull();

        if ($contexto === null || $contexto->membership === null) {
            return null;
        }

        $tenant = Tenant::query()->where('ulid', $tenantUlid)->first();

        // El canal nombra un negocio y la sesión está en otro. Puede ser un cliente viejo con un canal guardado o
        // alguien probando: en los dos casos, no.
        if ($tenant === null || (int) $tenant->id !== (int) $contexto->membership->tenant_id) {
            return null;
        }

        $branch = Branch::query()->where('ulid', $branchUlid)->first();

        return $branch !== null && (int) $branch->tenant_id === (int) $tenant->id ? $branch : null;
    }
}
