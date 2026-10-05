<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Application\AppSessions;
use App\Modules\Identity\Http\Requests\CloseOtherWebSessionsRequest;
use App\Modules\Identity\Http\Resources\AppSessionResource;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mis sesiones: salir de verdad en la app, «Mis dispositivos» y cerrar mis otras sesiones web (diseño de acceso,
 * fase 1).
 *
 * Todo es de la PERSONA, no del negocio: no exige permiso —como las preferencias o las notificaciones propias— y actúa
 * siempre sobre la cuenta de quien pide, nunca sobre otra. Por eso son excepciones declaradas en `RoutePermissionTest`.
 * Cerrar las sesiones de OTRA persona es otra cosa y vive en `MembershipAppSessionController`, con permiso.
 *
 * La lista cruza negocios a propósito: son las sesiones de esta cuenta, en cada negocio donde la persona trabaja, y la
 * persona los conoce todos. Al cerrarlas, el asiento va a la bitácora del negocio de cada una (`AppSessions`).
 */
final class AppSessionController
{
    public function __construct(
        private readonly AppSessions $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Salir en la app: revoca el token con el que se llama. Antes la app sólo lo borraba del teléfono y en el servidor
     * seguía valiendo para siempre.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $this->user($request)->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new ConflictHttpException('Esta sesión es de un navegador, no de la app: para salir usa «Salir».');
        }

        $this->sessions->revoke($token, 'logout');

        return new JsonResponse(null, 204);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $tokens = $this->user($request)->tokens()
            ->with('tenant:id,ulid,name')
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get();

        return AppSessionResource::collection($tokens);
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        $token = $this->user($request)->tokens()->where('ulid', Str::upper($session))->first();

        // Una sesión de otra cuenta responde igual que una que no existe: no se confirma que exista.
        if (! $token instanceof PersonalAccessToken) {
            throw new NotFoundHttpException('Esa sesión no existe o ya se cerró.');
        }

        $this->sessions->revoke($token, 'self');

        return new JsonResponse(null, 204);
    }

    /**
     * Cierra todas mis sesiones de la app, en todos mis negocios. Si se pide desde la app, incluida ésta.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $cerradas = $this->sessions->revokeAllOf($this->user($request), 'self');

        return new JsonResponse(['data' => ['closed' => $cerradas]]);
    }

    /**
     * Cierra mis otras sesiones en navegadores y conserva ésta.
     *
     * No hay lista de sesiones web —en producción viven en Redis, donde no se buscan por persona (decisión 4 del
     * diseño)—: `logoutOtherDevices` vuelve a cifrar la contraseña y `AuthenticateSession` saca a toda sesión que guardó
     * el cifrado anterior, en la API y en las pantallas. Ésta guarda el nuevo al terminar la petición y sigue.
     */
    public function closeOtherWeb(CloseOtherWebSessionsRequest $request): JsonResponse
    {
        $usuario = $this->user($request);

        if ($usuario->currentAccessToken() instanceof PersonalAccessToken) {
            throw new ConflictHttpException('Esto se hace desde el navegador: la app no tiene sesiones web que cerrar.');
        }

        $request->ensureIsNotRateLimited();

        $password = $request->string('password')->toString();

        if (! Hash::check($password, (string) $usuario->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
        }

        $request->clearRateLimiter();

        Auth::guard('web')->logoutOtherDevices($password);

        $this->audit->log(action: AuditAction::OTHER_WEB_SESSIONS_CLOSED);

        return new JsonResponse(null, 204);
    }

    /**
     * La cuenta de quien pide. El operador de una terminal compartida no tiene cuenta (ADR-012): estas pantallas no son
     * para él.
     */
    private function user(Request $request): User
    {
        $usuario = $request->user();

        if (! $usuario instanceof User) {
            throw new AccessDeniedHttpException('La terminal compartida no tiene una cuenta propia.');
        }

        return $usuario;
    }
}
