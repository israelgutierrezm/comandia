<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\AppSessions;
use App\Modules\Identity\Http\Resources\AppSessionResource;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Las sesiones de la app de OTRA persona, en ESTE negocio (diseño de acceso, fase 1): el teléfono perdido de un mesero.
 *
 * Con el permiso de suspender (`identity.users.suspend`), que ya revoca todas al suspender: quien puede dejar a alguien
 * fuera del negocio puede cerrarle una sesión.
 *
 * Sólo los tokens de esta membresía, que son de este negocio por construcción (D69). Las sesiones que la misma persona
 * tenga en otro negocio no se ven ni se cierran desde aquí; ni siquiera se sabe si existen.
 */
final class MembershipAppSessionController
{
    public function __construct(private readonly AppSessions $sessions) {}

    public function index(TenantMembership $membership): AnonymousResourceCollection
    {
        $tokens = PersonalAccessToken::query()
            ->where('tenant_id', $membership->tenantId())
            ->where('membership_id', $membership->id)
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get();

        return AppSessionResource::collection($tokens);
    }

    public function destroy(TenantMembership $membership, string $session): JsonResponse
    {
        $token = PersonalAccessToken::query()
            ->where('tenant_id', $membership->tenantId())
            ->where('membership_id', $membership->id)
            ->where('ulid', Str::upper($session))
            ->first();

        if ($token === null) {
            throw new NotFoundHttpException('Esa sesión no existe o ya se cerró.');
        }

        $this->sessions->revoke($token, 'admin');

        return new JsonResponse(null, 204);
    }
}
