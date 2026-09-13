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
use App\Modules\Shared\Application\Auth\TerminalDeviceState;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Http\Middleware\ResolveSharedTerminalToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        $device = $this->deviceFromSecret($request->string('secret')->toString());

        if ($device === null) {
            return $this->rejectDevice();
        }

        $device->touchLastSeen();
        $this->session->establishDevice($device);

        // La pantalla de bloqueo muestra QUÉ terminal es. Sin secreto ni operador: queda en el bloqueo.
        return $this->deviceResponse($device);
    }

    /**
     * Canjea el secreto por un TOKEN de dispositivo, para el kiosco móvil (ADR-014).
     *
     * El gemelo por token de {@see self::openSession()}: valida el MISMO secreto de enrolamiento, pero en
     * vez de una sesión (cookie) emite un token de dispositivo —hasheado como el del agente de impresión y
     * mostrado UNA sola vez—. Re-emparejar genera un token nuevo y anula el anterior. Tampoco exige auth:
     * el secreto es lo que establece la identidad.
     */
    public function issueToken(OpenDeviceSessionRequest $request): JsonResponse
    {
        $device = $this->deviceFromSecret($request->string('secret')->toString());

        if ($device === null) {
            return $this->rejectDevice();
        }

        // 48 bytes aleatorios, hasheados en base (sha256, sin sal: el token lo genera el servidor y no hay
        // escenario de colisión; lo que importa es que un volcado no entregue tokens usables).
        $plain = Str::random(48);
        $device->forceFill(['token_hash' => hash('sha256', $plain)])->save();
        $device->touchLastSeen();

        // El token viaja en claro UNA vez, como el secreto al enrolar. El dispositivo queda en el bloqueo.
        return $this->deviceResponse($device, ['token' => $plain]);
    }

    /**
     * Identifica al operador por código + PIN sobre un TOKEN de dispositivo ya resuelto (ADR-014).
     *
     * El gemelo por token de {@see self::identify()}: gateado por `device.token` (el dispositivo lo dejó
     * {@see ResolveSharedTerminalToken} en la petición) y por `throttle:pin`. El éxito fija al operador en
     * la FILA del dispositivo, no en una sesión.
     */
    public function identifyByToken(IdentifyOperatorRequest $request): Response
    {
        $device = $this->deviceFromRequest($request);
        $terminal = Terminal::query()->find($device->terminal_id);

        if ($terminal === null) {
            // El dispositivo apunta a una terminal que ya no existe: se olvida al operador y se exige re-emparejar.
            (new TerminalDeviceState($device))->clearOperator();

            return $this->rejectDevice();
        }

        $membership = $this->login->authenticate(
            $request->string('employee_code')->toString(),
            $request->string('pin')->toString(),
            (int) $terminal->branch_id,
        );

        (new TerminalDeviceState($device))->setOperator((int) $membership->id);

        return response()->noContent();
    }

    /**
     * Salir en el kiosco móvil: olvida al operador en la fila del dispositivo (ADR-014). Idempotente.
     */
    public function releaseByToken(Request $request): Response
    {
        (new TerminalDeviceState($this->deviceFromRequest($request)))->clearOperator();

        return response()->noContent();
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
     * Valida el secreto `{ulid}|{secreto}` y devuelve el dispositivo, o `null` si algo no cuadra.
     *
     * Compartido por el canje a sesión (cookie) y el canje a token (móvil). Busca la fila por ulid SIN
     * scope de tenant —todavía no hay contexto, y el ulid es único global y no adivinable—, verifica el
     * secreto con el hash, abre el scope de tenant DEL DISPOSITIVO (ADR-002) y comprueba que la terminal
     * siga activa Y compartida. Todos los fallos devuelven `null`: quien llama responde el MISMO 401, para
     * no convertir el endpoint en un oráculo de ulids válidos.
     */
    private function deviceFromSecret(string $secret): ?TerminalDevice
    {
        [$ulid, $plain] = $this->splitSecret($secret);

        $device = $ulid === null
            ? null
            : TerminalDevice::withoutGlobalScopes()->where('ulid', $ulid)->first();

        if ($device === null || $device->isRevoked() || $plain === null
            || ! Hash::check($plain, (string) $device->secret_hash)) {
            return null;
        }

        $this->tenantContext->set((int) $device->tenant_id);

        $terminal = Terminal::query()->find($device->terminal_id);

        if ($terminal === null || ! $terminal->isActive() || ! $terminal->isShared()) {
            return null;
        }

        return $device;
    }

    /** El dispositivo que {@see ResolveSharedTerminalToken} dejó en la petición (garantizado por `device.token`). */
    private function deviceFromRequest(Request $request): TerminalDevice
    {
        /** @var TerminalDevice $device */
        $device = $request->attributes->get(ResolveSharedTerminalToken::ATRIBUTO);

        return $device;
    }

    /**
     * Respuesta del dispositivo con su sucursal (para la pantalla de bloqueo), más lo extra que se pase
     * (p. ej. el token recién emitido). El Resource nunca expone secreto ni token.
     *
     * @param  array<string, mixed>  $extra
     */
    private function deviceResponse(TerminalDevice $device, array $extra = []): JsonResponse
    {
        $device->load('terminal.branch');

        return (new TerminalDeviceResource($device))
            ->additional(array_merge($extra, [
                'branch' => [
                    'ulid' => $device->terminal?->branch?->ulid,
                    'name' => $device->terminal?->branch?->name,
                ],
            ]))
            ->response();
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
