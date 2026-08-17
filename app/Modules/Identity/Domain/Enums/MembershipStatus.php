<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enums;

/**
 * Estado de la membresía usuario–tenant (ESPECIFICACIÓN_MAESTRA §4.1).
 *
 * `Terminated` y no borrado (D80): la persona tiene documentos históricos
 * apuntándole —ventas, comandas, autorizaciones, filas de auditoría— y borrarla
 * destruiría trazabilidad. El ciclo de vida se modela con estado.
 */
enum MembershipStatus: string
{
    /** Alta creada, la persona todavía no aceptó ni fijó credenciales. */
    case Invited = 'invited';

    /** Puede operar. */
    case Active = 'active';

    /** Suspendida temporalmente: no entra, pero conserva su historia y su PIN. */
    case Suspended = 'suspended';

    /** Baja definitiva. */
    case Terminated = 'terminated';

    /**
     * ¿Puede esta membresía operar en el sistema?
     *
     * Sólo `Active`. Una membresía invitada todavía no tiene credenciales fijadas,
     * y ni la suspendida ni la terminada deben poder autenticarse ni autorizar por
     * PIN.
     */
    public function canOperate(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invitada',
            self::Active => 'Activa',
            self::Suspended => 'Suspendida',
            self::Terminated => 'Baja',
        };
    }
}
