<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Http\Requests\SetMembershipPinRequest;
use App\Modules\Identity\Http\Resources\MembershipResource;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;

/**
 * PIN de terminal de una membresía (§4.1, D54, D84).
 *
 * Tres acciones distintas a propósito, porque son tres intenciones distintas:
 *
 *   - `set` — la persona olvidó su PIN o se le asigna el primero.
 *   - `unlock` — se equivocó cinco veces con prisa; el PIN sigue siendo válido. Forzar un cambio
 *     aquí obligaría a que el gerente conozca el PIN nuevo de otra persona.
 *   - `remove` — deja de poder autorizar acciones sensibles, sin dejar de trabajar.
 *
 * La respuesta nunca incluye el PIN, ni al fijarlo: devolverlo "sólo esa vez" significaría que
 * existe en claro en un log de respuesta, en la memoria del navegador y en el historial de la
 * herramienta con que se probó la API.
 */
final class MembershipPinController
{
    public function __construct(private readonly ManageMembershipPin $pins) {}

    public function set(
        SetMembershipPinRequest $request,
        TenantMembership $membership,
    ): MembershipResource {
        $this->pins->set($membership, $request->string('pin')->toString());

        return $this->resource($membership);
    }

    public function unlock(TenantMembership $membership): MembershipResource
    {
        $this->pins->unlock($membership);

        return $this->resource($membership);
    }

    public function remove(TenantMembership $membership): MembershipResource
    {
        $this->pins->remove($membership);

        return $this->resource($membership);
    }

    private function resource(TenantMembership $membership): MembershipResource
    {
        return new MembershipResource(
            $membership->refresh()->load(['user', 'employeeProfile', 'defaultRole', 'branchScopes.branch'])
        );
    }
}
