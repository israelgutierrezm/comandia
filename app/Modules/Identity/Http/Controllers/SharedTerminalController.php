<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\SharedTerminal\SharedTerminalLogin;
use App\Modules\Identity\Http\Requests\IdentifyOperatorRequest;
use App\Modules\Identity\Http\Requests\OpenDeviceSessionRequest;
use App\Modules\Organization\Http\Resources\TerminalDeviceResource;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Application\Auth\SharedTerminalSession;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

/**
 * Superficie SIN usuario de la terminal compartida (ADR-012).
 *
 * Tres pasos de la pantalla de bloqueo, ninguno con sesión de usuario:
 *
 *  1. {@see self::openSession()} — el dispositivo canja su secreto por una **sesión de dispositivo**.
 *  2. {@see self::identify()} — sobre esa sesión, un operador se identifica por **código + PIN**.
 *  3. {@see self::release()} — **Salir**: se olvida al operador y la terminal vuelve al bloqueo.
 *
 * Vive en Identity —no en Organization— porque es autenticación: canjear un secreto y validar un PIN son
 * el equivalente, por el lado sin usuario, de `auth/token` y `authorizations`. El enrolamiento (crear la
 * credencial, con permiso y sesión de usuario) sí es administración y vive en Organization.
 */
final class SharedTerminalController
{
    public function __construct(
        private readonly SharedTerminalSession $session,
        private readonly SharedTerminalLogin $login,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Canjea el secreto del dispositivo por una sesión de dispositivo.
     *
     * NO exige autenticación: es lo que la establece. El secreto llega como `{ulid}|{secreto}`; se busca
     * la fila por ulid SIN scope de tenant —todavía no hay contexto, y el ulid es único global y no
     * adivinable— y se verifica el secreto con el hash. Todos los fallos dan el MISMO 401: distinguir
     * "no existe" de "secreto malo" convertiría el endpoint en un oráculo de ulids válidos.
     */
    public function openSession(OpenDeviceSessionRequest $request): JsonResponse
    {
        [$ulid, $plain] = $this->splitSecret($request->string('secret')->toString());

        $device = $ulid === null
            ? null
            : TerminalDevice::withoutGlobalScopes()->where('ulid', $ulid)->first();

        if ($device === null || $device->isRevoked() || $plain === null
            || ! Hash::check($plain, (string) $device->secret_hash)) {
            return $this->rejectDevice();
        }

        // Abrir el contexto del dispositivo para validar la terminal (modelo con scope de tenant). El
        // tenant sale del DISPOSITIVO, nunca de la petición (ADR-002), igual que el agente de impresión.
        $this->tenantContext->set((int) $device->tenant_id);

        $terminal = Terminal::query()->find($device->terminal_id);

        // La terminal tiene que seguir activa Y compartida: si se dio de baja o se le quitó el modo
        // compartido, el dispositivo enrolado ya no opera. Mismo 401 indistinguible.
        if ($terminal === null || ! $terminal->isActive() || ! $terminal->isShared()) {
            return $this->rejectDevice();
        }

        $device->touchLastSeen();
        $this->session->establishDevice($device);

        // La pantalla de bloqueo muestra QUÉ terminal es. No hay secreto en esta respuesta (el Resource no
        // lo expone) ni operador todavía: el dispositivo queda en el bloqueo.
        return (new TerminalDeviceResource($device->load('terminal')))
            ->additional([
                'branch' => [
                    'ulid' => $terminal->branch?->ulid,
                    'name' => $terminal->branch?->name,
                ],
            ])
            ->response();
    }

    /**
     * Identifica al operador por código de empleado + PIN sobre una sesión de dispositivo ya establecida.
     *
     * Gateado por `device.session` (exige la capa de dispositivo) y por `throttle:pin` (D55). El PIN se
     * valida contra la sucursal del dispositivo reutilizando el bloqueo por intentos (D54). Un fallo da
     * 422/423 indistinguible; el éxito deja al operador en la sesión y la siguiente petición al POS ya
     * pasa el gate con su rol activo.
     */
    public function identify(IdentifyOperatorRequest $request): Response
    {
        $terminalId = $this->session->terminalId();
        $terminal = $terminalId === null ? null : Terminal::query()->find($terminalId);

        if ($terminal === null) {
            // El dispositivo apunta a una terminal que ya no existe: se olvida todo y se exige re-enrolar.
            $this->session->clearAll();

            return $this->rejectDevice();
        }

        // Lanza PinAuthorizationFailed (422/423) en cualquier fallo; ApiProblem lo formatea. No se
        // distingue código inexistente, PIN incorrecto ni fuera de sucursal (mismo criterio que ADR-008).
        $membership = $this->login->authenticate(
            $request->string('employee_code')->toString(),
            $request->string('pin')->toString(),
            (int) $terminal->branch_id,
        );

        $this->session->setOperator((int) $membership->id);

        return response()->noContent();
    }

    /**
     * Salir: se olvida al operador y la terminal vuelve al bloqueo. El dispositivo permanece.
     *
     * Idempotente: salir sin operador (la terminal ya bloqueada) no es un error. Gateado por
     * `device.session`, no por operador: uno siempre puede cerrar su propia sesión.
     */
    public function release(): Response
    {
        $this->session->clearOperator();

        return response()->noContent();
    }

    /**
     * Parte el secreto `{ulid}|{secreto}`. Devuelve `[null, null]` si no tiene esa forma.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitSecret(string $secret): array
    {
        $pos = strpos($secret, '|');

        if ($pos === false) {
            return [null, null];
        }

        $ulid = substr($secret, 0, $pos);
        $plain = substr($secret, $pos + 1);

        if ($ulid === '' || $plain === '') {
            return [null, null];
        }

        return [$ulid, $plain];
    }

    private function rejectDevice(): JsonResponse
    {
        return new JsonResponse([
            'type' => 'invalid_device_secret',
            'title' => 'El secreto del dispositivo no es válido o fue revocado. Enrola la terminal de nuevo.',
            'status' => 401,
        ], 401);
    }
}
