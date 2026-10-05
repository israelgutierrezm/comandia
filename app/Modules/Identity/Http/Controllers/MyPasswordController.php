<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\PasswordChanges;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Cambiar mi contraseña (diseño de acceso, fase 2).
 *
 * De la persona, no del negocio: sin permiso —excepción declarada en `RoutePermissionTest`, como el resto de «Mi
 * cuenta»— y siempre sobre la cuenta de quien pide. La sesión desde la que se cambia sigue abierta; todas las demás se
 * cierran (`PasswordChanges`).
 */
final class MyPasswordController
{
    public function __construct(private readonly PasswordChanges $passwords) {}

    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            throw new AccessDeniedHttpException('La terminal compartida no tiene una cuenta propia.');
        }

        $request->ensureIsNotRateLimited();

        if (! Hash::check($request->string('current_password')->toString(), (string) $usuario->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['current_password' => ['La contraseña actual no es correcta.']]);
        }

        $request->clearRateLimiter();

        $actual = $usuario->currentAccessToken();

        $this->passwords->change(
            $usuario,
            $request->string('password')->toString(),
            keep: $actual instanceof PersonalAccessToken ? $actual : null,
        );

        return new JsonResponse(null, 204);
    }
}
