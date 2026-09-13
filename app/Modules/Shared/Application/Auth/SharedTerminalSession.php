<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Auth;

use App\Modules\Organization\Infrastructure\Models\TerminalDevice;
use Illuminate\Support\Facades\Date;

/**
 * El contrato de la sesión de una terminal compartida (ADR-012): las llaves y su lectura/escritura,
 * en un solo lugar, para que el middleware y los endpoints (enrolar, operar, salir) no dispersen
 * cadenas mágicas ni se desincronicen.
 *
 * Dos capas dentro de la misma sesión del navegador:
 *  - **Dispositivo**: quién es esta terminal (id del `terminal_device`, su tenant y su terminal). Lo
 *    fija el canje del secreto y dura mientras viva la sesión del navegador.
 *  - **Operador**: qué membresía está operando ahora, con su marca de actividad. Lo fija el PIN y
 *    caduca por inactividad o al salir. Sin operador, la terminal está "en el bloqueo".
 *
 * La capa de operador satisface {@see SharedTerminalState}, para que {@see SharedTerminalResolver} la
 * resuelva igual que el respaldo por token del kiosco móvil ({@see TerminalDeviceState}).
 */
final class SharedTerminalSession implements SharedTerminalState
{
    private const DEVICE = 'shared_terminal.device_id';
    private const TENANT = 'shared_terminal.tenant_id';
    private const TERMINAL = 'shared_terminal.terminal_id';
    private const OPERATOR = 'shared_terminal.operator_membership_id';
    private const ACTIVITY = 'shared_terminal.operator_last_activity';

    // ---- Capa de dispositivo ----

    public function establishDevice(TerminalDevice $device): void
    {
        session()->put(self::DEVICE, (int) $device->id);
        session()->put(self::TENANT, (int) $device->tenant_id);
        session()->put(self::TERMINAL, (int) $device->terminal_id);
        // Un dispositivo recién canjeado empieza SIN operador: en el bloqueo.
        session()->forget([self::OPERATOR, self::ACTIVITY]);
    }

    public function hasDevice(): bool
    {
        return session()->has(self::DEVICE);
    }

    public function deviceId(): ?int
    {
        $id = session()->get(self::DEVICE);

        return is_int($id) ? $id : null;
    }

    public function tenantId(): ?int
    {
        $id = session()->get(self::TENANT);

        return is_int($id) ? $id : null;
    }

    public function terminalId(): ?int
    {
        $id = session()->get(self::TERMINAL);

        return is_int($id) ? $id : null;
    }

    // ---- Capa de operador ----

    public function setOperator(int $membershipId): void
    {
        session()->put(self::OPERATOR, $membershipId);
        $this->touchActivity();
    }

    public function operatorMembershipId(): ?int
    {
        $id = session()->get(self::OPERATOR);

        return is_int($id) ? $id : null;
    }

    public function touchActivity(): void
    {
        session()->put(self::ACTIVITY, Date::now()->getTimestamp());
    }

    /** Segundos desde la última actividad del operador; `null` si no hay operador. */
    public function secondsSinceActivity(): ?int
    {
        $at = session()->get(self::ACTIVITY);

        return is_int($at) ? max(0, Date::now()->getTimestamp() - $at) : null;
    }

    /** Salir / caducidad: se olvida el operador, el dispositivo permanece (vuelve al bloqueo). */
    public function clearOperator(): void
    {
        session()->forget([self::OPERATOR, self::ACTIVITY]);
    }

    /** Dispositivo revocado o inválido: se olvida todo. */
    public function clearAll(): void
    {
        session()->forget([self::DEVICE, self::TENANT, self::TERMINAL, self::OPERATOR, self::ACTIVITY]);
    }
}
