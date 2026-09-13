<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Auth;

/**
 * La capa de OPERADOR de una terminal compartida, con dos respaldos intercambiables (ADR-012/ADR-014):
 *
 *  - {@see SharedTerminalSession} — en la sesión (cookie), para el POS web.
 *  - {@see TerminalDeviceState} — en la fila del dispositivo, para el kiosco móvil por token.
 *
 * Existe para que {@see SharedTerminalResolver} resuelva al operador —inactividad, rol activo, contexto—
 * exactamente igual por los dos caminos, sin duplicar esa lógica delicada. Sólo cubre al operador; la
 * capa de dispositivo la establece cada camino por su lado (canje de secreto → sesión, o → token).
 */
interface SharedTerminalState
{
    /** La membresía que opera ahora, o `null` si la terminal está en el bloqueo. */
    public function operatorMembershipId(): ?int;

    /** Segundos desde la última actividad del operador; `null` si no hay operador. */
    public function secondsSinceActivity(): ?int;

    /** Refresca la marca de actividad del operador (inactividad deslizante). */
    public function touchActivity(): void;

    /** Fija al operador y arranca su marca de actividad. */
    public function setOperator(int $membershipId): void;

    /** Salir / caducidad: se olvida al operador y la terminal vuelve al bloqueo. */
    public function clearOperator(): void;
}
