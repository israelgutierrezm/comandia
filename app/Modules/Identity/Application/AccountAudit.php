<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;

/**
 * La bitácora de los hechos de una CUENTA, que no son de un solo negocio (diseño de acceso).
 *
 * ## Por qué hace falta, si ya existe `AuditLogger`
 *
 * `AuditLogger` toma el negocio y el actor del contexto de la petición, y eso es lo correcto casi siempre. Pero la
 * cuenta es de la plataforma: una persona con dos negocios tiene UNA contraseña y sesiones de la app en los dos. Cerrar
 * desde el negocio A una sesión que abrió en B, o cambiar la contraseña con la que entra a ambos, es un hecho de B que
 * ocurre durante una petición de A. Asentarlo con el contexto de A pondría en la bitácora de B a una membresía de A —un
 * dato de otro negocio— o lo dejaría fuera de la bitácora que su dueño revisa.
 *
 * Así que esto asienta **como la persona misma, en cada negocio**: abre el negocio de la membresía y un contexto con
 * esa membresía como actor, el tiempo justo de escribir. Recorre sólo las membresías de esa persona —la misma lectura
 * que ya hace el acceso para elegir negocio— y nunca lee nada más de otro negocio.
 */
final readonly class AccountAudit
{
    public function __construct(
        private AuditLogger $audit,
        private TenantContext $tenants,
        private ContextHolder $holder,
    ) {}

    /**
     * Asienta como la persona dueña de la membresía, en el negocio de esa membresía.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function asMember(int $tenantId, int $membershipId, string $action, ?array $before = null, ?array $after = null): void
    {
        $this->tenants->runFor($tenantId, function () use ($membershipId, $action, $before, $after): void {
            $membership = TenantMembership::query()->with(['user', 'tenant'])->find($membershipId);

            if ($membership === null || $membership->user === null || $membership->tenant === null) {
                return;
            }

            $context = RequestContext::forMember($membership->tenant, $membership->user, $membership);

            $this->holder->runWith($context, fn () => $this->audit->log(
                action: $action,
                auditable: $membership,
                before: $before,
                after: $after,
            ));
        });
    }

    /**
     * Asienta un hecho de la cuenta en CADA negocio donde la persona tiene acceso activo: cambiar o restablecer la
     * contraseña cambia quién puede entrar a cada uno, y cada dueño tiene que poder verlo en su bitácora.
     *
     * @param  array<string, mixed>|null  $after
     */
    public function inEveryBusinessOf(User $user, string $action, ?array $after = null): void
    {
        // Cross-tenant legítimo del flujo de identidad: las membresías de ESTA persona, como el selector de negocio.
        $membresias = $user->membershipsAcrossTenants()
            ->where('status', MembershipStatus::Active->value)
            ->get(['id', 'tenant_id']);

        foreach ($membresias as $membresia) {
            $this->asMember((int) $membresia->tenant_id, (int) $membresia->id, $action, after: $after);
        }
    }

    /**
     * Asienta sin actor —lo hizo el sistema— en un negocio. Para lo que corre sin petición, como la caducidad por falta
     * de uso: el contexto está vacío y el asiento queda a nombre de «Sistema».
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function asSystem(int $tenantId, int $membershipId, string $action, ?array $before = null, ?array $after = null): void
    {
        // Sin contexto de petición aunque lo hubiera: un actor que quedara de otra cosa firmaría lo que no hizo.
        $previo = $this->holder->getOrNull();
        $this->holder->forget();

        try {
            $this->tenants->runFor($tenantId, function () use ($membershipId, $action, $before, $after): void {
                $membership = TenantMembership::query()->find($membershipId);

                $this->audit->log(action: $action, auditable: $membership, before: $before, after: $after);
            });
        } finally {
            if ($previo !== null) {
                $this->holder->set($previo);
            }
        }
    }
}
