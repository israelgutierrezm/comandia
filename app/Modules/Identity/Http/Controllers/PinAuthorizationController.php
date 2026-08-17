<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Http\Requests\PinAuthorizationRequest;
use Illuminate\Http\JsonResponse;

/**
 * "Acción autorizada por PIN" (ESPECIFICACIÓN_MAESTRA §4.2, ADR-008).
 *
 * El gerente se acerca a la terminal del mesero, teclea su código y su PIN, y el sistema
 * devuelve una autorización de un solo uso que la operación siguiente presenta. La terminal
 * permanece abierta; la autorización no.
 *
 * Devuelve el nombre del autorizador para que la terminal lo muestre: es la
 * retroalimentación que hace visible al operador **quién** quedó registrado como actor real,
 * y por tanto un desincentivo directo a compartir PIN.
 */
final class PinAuthorizationController
{
    public function __invoke(
        PinAuthorizationRequest $request,
        PinAuthorizationService $service,
    ): JsonResponse {
        $grant = $service->grant(
            employeeCode: (string) $request->string('employee_code'),
            pin: (string) $request->string('pin'),
            permission: (string) $request->string('permission'),
        );

        return new JsonResponse([
            'data' => [
                'token' => $grant->token,
                'permission' => $grant->permission,
                'authorized_by' => [
                    'ulid' => $grant->authorizerUlid,
                    'name' => $grant->authorizerName,
                ],
                'expires_in_seconds' => $grant->secondsToExpire(),
            ],
        ], 201);
    }
}
