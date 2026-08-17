<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolución del contexto de tenant (ADR-002, ARQUITECTURA_MAESTRA §3 y §8).
 *
 * Es el único lugar del sistema donde se decide en qué tenant estamos, y la regla que
 * lo gobierna no admite matices: **el `tenant_id` jamás llega como parámetro del
 * cliente**. Sale de la sesión (SPA web) o del token (app Flutter, agentes de
 * impresión).
 *
 * ## Qué SÍ puede elegir el cliente
 *
 * La sucursal activa, el rol activo y la terminal, mediante los headers `X-Branch`,
 * `X-Role` y `X-Terminal`. Y siempre validados contra lo que la membresía ya tiene
 * concedido: el cliente elige **entre** sus opciones, no las inventa. La diferencia
 * con el tenant es sustantiva —el tenant no es una elección, es quién eres— y por eso
 * viaja por un canal distinto.
 */
final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ContextHolder $holder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->rejectClientSuppliedTenant($request);

        $user = $request->user();

        if (! $user instanceof User) {
            // Sin usuario autenticado no hay contexto que resolver. La ruta se
            // encargará de exigir autenticación si le corresponde.
            return $next($request);
        }

        $tenantId = $this->resolveTenantId($request, $user);

        if ($tenantId === null) {
            throw new HttpException(409, 'Selecciona un negocio para continuar.');
        }

        // El contexto de tenant se abre ANTES de consultar cualquier modelo de dominio:
        // la membresía, la sucursal y el rol llevan global scope y sin contexto lanzan.
        $this->tenantContext->set($tenantId);

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null || ! $tenant->allowsAccess()) {
            throw new HttpException(403, 'Esta cuenta no está disponible.');
        }

        $membership = $this->resolveMembership($user, $tenantId);

        $branch = $this->resolveActiveBranch($request, $membership);
        $role = $this->resolveActiveRole($request, $membership);
        $terminal = $this->resolveTerminal($request, $branch);

        $this->holder->set(RequestContext::forMember(
            tenant: $tenant,
            user: $user,
            membership: $membership,
            activeRole: $role,
            activeBranch: $branch,
            terminal: $terminal,
        ));

        return $next($request);
    }

    /**
     * Si el cliente manda `tenant_id`, se rechaza con 422 en lugar de ignorarlo.
     *
     * No es purismo: un cliente que lo envía está confundido o está probando el
     * aislamiento, y las dos cosas se quieren ver. Ignorarlo en silencio dejaría a un
     * atacante sin señal de que ese camino está cerrado, y a un desarrollador sin
     * señal de que su petición no hace lo que cree.
     */
    private function rejectClientSuppliedTenant(Request $request): void
    {
        if ($request->hasHeader('X-Tenant') || $request->has('tenant_id')) {
            throw new HttpException(
                422,
                'El negocio no se envía en la petición: se resuelve de la sesión o del token.'
            );
        }
    }

    /**
     * Origen del tenant, por orden de autoridad.
     */
    private function resolveTenantId(Request $request, User $user): ?int
    {
        // 1. Token de API: el tenant viaja con la credencial (D69) y no se negocia.
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return (int) $token->tenant_id;
        }

        // 2. Sesión de la SPA: el tenant se fija al iniciar sesión, con selección
        //    explícita cuando la persona pertenece a varios (§2).
        $fromSession = $request->hasSession() ? $request->session()->get('tenant_id') : null;

        return is_int($fromSession) ? $fromSession : null;
    }

    private function resolveMembership(User $user, int $tenantId): TenantMembership
    {
        $membership = TenantMembership::query()
            ->where('user_id', $user->id)
            ->with(['user', 'employeeProfile', 'defaultRole'])
            ->first();

        if ($membership === null || ! $membership->canOperate()) {
            // Cubre el caso crítico: una membresía suspendida DESPUÉS de emitirse un
            // token tiene que dejar de operar de inmediato. Por eso se revalida en cada
            // petición y no sólo al emitir la credencial.
            throw new HttpException(403, 'No tienes acceso a este negocio.');
        }

        return $membership;
    }

    /**
     * Sucursal activa, en cascada: header → última usada → la única del alcance.
     */
    private function resolveActiveBranch(Request $request, TenantMembership $membership): ?Branch
    {
        $scoped = $membership->scopedBranchIds();

        if ($scoped === []) {
            return null;
        }

        $requested = $request->header('X-Branch');

        if (is_string($requested) && $requested !== '') {
            $branch = Branch::findByUlid($requested);

            // Validado contra el alcance: viene del cliente, así que no se cree nada.
            if ($branch === null || ! in_array($branch->id, $scoped, strict: true)) {
                throw new HttpException(403, 'No tienes acceso a esa sucursal.');
            }

            return $branch;
        }

        if ($membership->last_active_branch_id !== null
            && in_array((int) $membership->last_active_branch_id, $scoped, strict: true)) {
            return Branch::query()->find($membership->last_active_branch_id);
        }

        // Con una sola sucursal en el alcance no hay nada que elegir; con varias, el
        // cliente tiene que decidir y el caso de uso que la necesite fallará con un
        // mensaje claro (RequestContext::requireActiveBranch).
        return count($scoped) === 1
            ? Branch::query()->find($scoped[0])
            : null;
    }

    /**
     * Rol activo: header validado contra los roles asignados, o el rol por defecto.
     *
     * NUNCA la suma de roles (D9). El cliente sólo puede elegir entre roles que ya
     * tiene, así que la elección es segura; lo que no es seguro es sumarlos.
     */
    private function resolveActiveRole(Request $request, TenantMembership $membership): ?Role
    {
        $requested = $request->header('X-Role');

        if (is_string($requested) && $requested !== '') {
            $role = Role::findByUlid($requested);

            if ($role === null || ! $this->membershipHasRole($membership, $role)) {
                throw new HttpException(403, 'No tienes ese rol asignado.');
            }

            return $role;
        }

        return $membership->defaultRole;
    }

    private function membershipHasRole(TenantMembership $membership, Role $role): bool
    {
        $user = $membership->user;

        if ($user === null) {
            return false;
        }

        // Consulta directa al pivote y no `$user->hasRole()`: lo segundo pasa por la
        // maquinaria de Spatie, que razona en términos de suma de roles y es
        // precisamente lo que este proyecto no usa para autorizar.
        return $role->users()->whereKey($user->id)->exists();
    }

    private function resolveTerminal(Request $request, ?Branch $branch): ?Terminal
    {
        $requested = $request->header('X-Terminal');

        if (! is_string($requested) || $requested === '' || $branch === null) {
            return null;
        }

        $terminal = Terminal::findByUlid($requested);

        if ($terminal === null || $terminal->branch_id !== $branch->id || ! $terminal->isActive()) {
            throw new HttpException(403, 'Terminal no válida para esta sucursal.');
        }

        return $terminal;
    }
}
