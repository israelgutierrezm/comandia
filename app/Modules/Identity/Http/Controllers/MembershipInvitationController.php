<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\MembershipInvitations;
use App\Modules\Identity\Http\Requests\StoreMembershipInvitationRequest;
use App\Modules\Identity\Http\Resources\MembershipResource;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;

/**
 * Invitar, reenviar y cancelar la invitación de una persona (diseño de acceso, fase 3).
 *
 * Con el permiso de dar de alta personal (`identity.users.create`): invitar es la mitad del alta —la que da acceso—, y
 * «Dar acceso» a alguien que ya estaba en nómina es lo mismo que haberlo dado de alta con correo.
 *
 * La respuesta de invitar lleva el ENLACE una sola vez, para copiarlo y mandarlo por otro medio si el correo no llega
 * (decisión 7 del diseño). Nunca vuelve a salir: la base guarda su hash.
 */
final class MembershipInvitationController
{
    public function __construct(
        private readonly MembershipInvitations $invitations,
        private readonly ContextHolder $context,
    ) {}

    public function store(StoreMembershipInvitationRequest $request, TenantMembership $membership): JsonResponse
    {
        $emitida = $this->invitations->invite(
            $membership,
            $request->string('email')->toString(),
            $request->roleUlids(),
            $this->context->get()->membership,
        );

        return (new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch', 'openInvitation'])
        ))
            ->additional(['meta' => ['invitation_link' => $emitida->link]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(TenantMembership $membership): JsonResponse
    {
        $this->invitations->revoke($membership);

        return new JsonResponse(null, 204);
    }
}
