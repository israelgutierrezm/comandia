<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Organization\Http\Requests\EnrollTerminalDeviceRequest;
use App\Modules\Organization\Http\Resources\TerminalDeviceResource;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Enrolamiento de dispositivos de terminal compartida (ADR-012).
 *
 * Enrolar es un acto de ADMINISTRACIÓN, hecho por alguien con sesión de usuario y el permiso
 * `organization.terminals.enroll`: entrega una CREDENCIAL de dispositivo que después opera sin usuario.
 * Por eso vive aquí, con el resto de la configuración de la terminal, y no en la superficie sin usuario
 * (canjear el secreto, identificar al operador), que es del módulo Identity.
 */
final class TerminalDeviceController
{
    use AssertsBranchScope;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Los dispositivos enrolados de una terminal, para gestionarlos (revocar el que se perdió, ver cuándo
     * canjeó por última vez). Vigentes primero, luego los revocados como historial.
     *
     * @return AnonymousResourceCollection<\Illuminate\Support\Collection<int, TerminalDevice>>
     */
    public function index(Terminal $terminal): AnonymousResourceCollection
    {
        $devices = TerminalDevice::query()
            ->where('terminal_id', $terminal->id)
            ->orderByRaw('revoked_at is null desc')
            ->latest('id')
            ->get();

        return TerminalDeviceResource::collection($devices);
    }

    /**
     * Revoca un dispositivo (aparato perdido/robado). Baja lógica, no borrado: la fila queda con
     * `revoked_at` para el historial, y el canje del secreto la rechaza en la siguiente petición.
     */
    public function revoke(TerminalDevice $terminalDevice): TerminalDeviceResource
    {
        // Alcance de sucursal: revocar la credencial de una caja es tocar esa sucursal, igual que enrolar.
        $terminal = Terminal::query()->find($terminalDevice->terminal_id);
        $this->assertBranchInScope($terminal?->branch_id);

        $terminalDevice->revoke();

        $this->audit->log(
            action: AuditAction::TERMINAL_DEVICE_REVOKED,
            auditable: $terminalDevice,
            after: [
                'label' => $terminalDevice->label,
                'terminal_id' => $terminalDevice->terminal_id,
            ],
        );

        return new TerminalDeviceResource($terminalDevice->refresh()->load('terminal'));
    }

    /**
     * Enrola un dispositivo a una terminal y la marca como compartida.
     *
     * El secreto se genera aquí, se guarda HASHEADO y se devuelve EN CLARO una sola vez, como
     * `{ulid}|{secreto}`: el ulid identifica la fila y el secreto la autentica (mismo patrón que una
     * llave de API). El navegador lo guarda; si se pierde, se revoca la fila y se enrola de nuevo — el
     * secreto no se puede volver a mostrar.
     */
    public function enroll(EnrollTerminalDeviceRequest $request, Terminal $terminal): JsonResponse
    {
        // El binding ya acotó la terminal al tenant (404 si es de otro). Falta el alcance de SUCURSAL:
        // quien sólo opera una sucursal no equipa la caja de otra — de esa credencial cuelga después la
        // operación real de esa caja.
        $this->assertBranchInScope($terminal->branch_id);

        // 40 bytes aleatorios del servidor: el espacio hace irrelevante que el hash no lleve sal por fila
        // en la práctica, y aun así se guarda con `Hash::make` (bcrypt) porque un volcado de la base no
        // debe entregar secretos usables.
        $plain = Str::random(40);

        $device = new TerminalDevice([
            'terminal_id' => $terminal->id,
            'label' => $request->string('label')->toString(),
        ]);
        $device->secret_hash = Hash::make($plain);
        $device->save();

        // La terminal pasa a modo compartido con su primer dispositivo. Idempotente: enrolar un segundo
        // aparato la deja igual de compartida.
        $becameShared = ! $terminal->isShared();

        if ($becameShared) {
            $terminal->update(['is_shared' => true]);
        }

        $this->audit->log(
            action: AuditAction::TERMINAL_DEVICE_ENROLLED,
            auditable: $device,
            after: [
                'label' => $device->label,
                'terminal_id' => $terminal->id,
                'terminal_marked_shared' => $becameShared,
            ],
        );

        return (new TerminalDeviceResource($device->load('terminal')))
            ->additional([
                // El secreto en claro, UNA sola vez. No es parte del Resource (que nunca lo expone): viaja
                // aparte, en esta respuesta, y no vuelve a estar disponible.
                'secret' => $device->ulid.'|'.$plain,
            ])
            ->response()
            ->setStatusCode(201);
    }
}
