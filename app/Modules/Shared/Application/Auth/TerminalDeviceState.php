<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Auth;

use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use Illuminate\Support\Facades\Date;

/**
 * La capa de operador de una terminal compartida respaldada por la FILA DEL DISPOSITIVO (ADR-014).
 *
 * Es el gemelo por token de {@see SharedTerminalSession}: la app móvil no tiene cookie donde guardar al
 * operador, así que vive en `terminal_devices.operator_membership_id` + `operator_last_activity_at`. Un
 * operador a la vez por dispositivo, que es el modelo de la terminal compartida. Escribe con `forceFill`
 * porque son columnas gobernadas por el servidor, no entrada del cliente.
 */
final class TerminalDeviceState implements SharedTerminalState
{
    public function __construct(private readonly TerminalDevice $device) {}

    public function operatorMembershipId(): ?int
    {
        $id = $this->device->operator_membership_id;

        return $id === null ? null : (int) $id;
    }

    public function secondsSinceActivity(): ?int
    {
        $at = $this->device->operator_last_activity_at;

        return $at === null ? null : max(0, Date::now()->getTimestamp() - $at->getTimestamp());
    }

    public function touchActivity(): void
    {
        $this->device->forceFill(['operator_last_activity_at' => Date::now()])->save();
    }

    public function setOperator(int $membershipId): void
    {
        $this->device->forceFill([
            'operator_membership_id' => $membershipId,
            'operator_last_activity_at' => Date::now(),
        ])->save();
    }

    public function clearOperator(): void
    {
        $this->device->forceFill([
            'operator_membership_id' => null,
            'operator_last_activity_at' => null,
        ])->save();
    }
}
