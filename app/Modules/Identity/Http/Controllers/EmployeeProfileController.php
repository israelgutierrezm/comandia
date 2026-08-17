<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Http\Requests\UpsertEmployeeProfileRequest;
use App\Modules\Identity\Http\Resources\EmployeeProfileResource;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Authorization\Authorize;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Perfil laboral de una persona (§4.1, capa 3).
 */
final class EmployeeProfileController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Authorize $authorize,
    ) {}

    public function show(TenantMembership $membership): EmployeeProfileResource
    {
        $profile = $membership->employeeProfile;

        if ($profile === null) {
            throw new NotFoundHttpException('Esta persona no tiene perfil de empleado.');
        }

        // Auditar la LECTURA, no sólo la escritura. Es la mitad de la protección que D77 pone en
        // lugar del cifrado: si el PII vive en claro, tiene que quedar registrado quién lo
        // consultó. Sólo se audita cuando de verdad se expuso.
        if ($this->authorize->allows('identity.employee_profiles.view_sensitive')) {
            $this->audit->log(
                action: AuditAction::SENSITIVE_PROFILE_VIEWED,
                auditable: $profile,
            );
        }

        return new EmployeeProfileResource($profile);
    }

    /**
     * Crea o actualiza el perfil.
     *
     * Un solo endpoint para las dos cosas porque la relación es 1:1 con la membresía: "crear el
     * perfil de Ana" y "editar el perfil de Ana" son la misma intención sobre el mismo recurso, y
     * separarlas obligaría al cliente a saber antes si existe.
     */
    public function upsert(
        UpsertEmployeeProfileRequest $request,
        TenantMembership $membership,
    ): EmployeeProfileResource {
        $profile = $membership->employeeProfile;

        $antes = $profile?->only([
            'legal_first_name', 'legal_paternal_surname', 'legal_maternal_surname',
            'is_foreigner', 'hired_at', 'terminated_at',
        ]);

        if ($profile === null) {
            $profile = EmployeeProfile::create(
                $request->safe()->all() + ['membership_id' => $membership->id]
            );
        } else {
            $profile->update($request->safe()->all());
        }

        // El PII no va al antes/después de la bitácora. La bitácora es consultable por quien
        // tenga permiso de auditoría, que no es necesariamente quien puede ver CURP y RFC:
        // volcarlo ahí sería una puerta lateral al permiso de PII.
        $this->audit->log(
            action: AuditAction::USER_CREATED,
            auditable: $profile,
            before: $antes,
            after: $profile->only([
                'legal_first_name', 'legal_paternal_surname', 'legal_maternal_surname',
                'is_foreigner', 'hired_at', 'terminated_at',
            ]),
        );

        return new EmployeeProfileResource($profile->refresh());
    }

    /**
     * Baja del perfil.
     *
     * Prohibida si la persona no tiene credenciales: el perfil es su única fuente de nombre
     * (invariante I1, D66), así que borrarlo la dejaría sin nombre en comandas y auditoría. Es la
     * mitad simétrica del invariante, la que suele faltar en las implementaciones.
     */
    public function destroy(TenantMembership $membership): JsonResponse
    {
        $profile = $membership->employeeProfile;

        if ($profile === null) {
            throw new NotFoundHttpException('Esta persona no tiene perfil de empleado.');
        }

        if (! $membership->hasCredentials()) {
            throw new ConflictHttpException(
                'No se puede eliminar el perfil de una persona sin credenciales de acceso: es de '
                .'donde sale su nombre (D66). Asígnale un acceso primero o dala de baja.'
            );
        }

        $profile->delete();

        return new JsonResponse(status: 204);
    }
}
