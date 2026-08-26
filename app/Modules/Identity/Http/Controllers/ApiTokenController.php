<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\IssueApiToken;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Http\Requests\ApiTokenRequest;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Emite un token de API para la app (Iteración 9). El equivalente por token del `LoginController` de la SPA.
 *
 * No exige permiso —es el endpoint que CREA la credencial, como `context` lista quién eres sin exigir uno—: por eso es
 * excepción declarada en `RoutePermissionTest`. El mensaje de credenciales no distingue «no existe» de «contraseña mala»,
 * para no filtrar qué correos están registrados. El token sale DESDE la membresía y lleva su `tenant_id` (D69).
 */
final class ApiTokenController
{
    public function __construct(private readonly IssueApiToken $issuer) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(ApiTokenRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $request->clearRateLimiter();

        // Cross-tenant legítimo del flujo de identidad, ANTES de que exista contexto: es la misma consulta que el
        // selector de negocio del login de la SPA.
        $memberships = $user->membershipsAcrossTenants()
            ->where('status', MembershipStatus::Active->value)
            ->with('tenant')
            ->get();

        if ($memberships->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => ['Tu cuenta no está activa en ningún negocio. Contacta a quien te dio de alta.'],
            ]);
        }

        $tenantUlid = $request->input('tenant_ulid');

        if ($tenantUlid !== null) {
            $membership = $memberships->first(fn (TenantMembership $m): bool => $m->tenant?->ulid === $tenantUlid);

            if ($membership === null) {
                throw ValidationException::withMessages(['tenant_ulid' => ['No perteneces a ese negocio.']]);
            }
        } elseif ($memberships->count() === 1) {
            $membership = $memberships->first();
        } else {
            // Varios negocios y no se eligió: el cliente reintenta con `tenant_ulid`. No es un error, es un paso.
            return new JsonResponse([
                'message' => 'Tu cuenta pertenece a más de un negocio. Elige uno y vuelve a pedir el token con «tenant_ulid».',
                'memberships' => $memberships->map(fn (TenantMembership $m): array => [
                    'tenant_ulid' => $m->tenant?->ulid,
                    'tenant_name' => $m->tenant?->name,
                ])->values(),
            ], 409);
        }

        $token = $this->issuer->issue($membership, $request->string('device_name')->toString());

        return new JsonResponse([
            'token' => $token->plainTextToken,
            'context' => [
                'tenant' => ['ulid' => $membership->tenant?->ulid, 'name' => $membership->tenant?->name],
                'membership' => ['ulid' => $membership->ulid],
            ],
        ], 201);
    }
}
