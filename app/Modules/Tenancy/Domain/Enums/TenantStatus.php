<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

/**
 * Estados del tenant (D70).
 *
 * La política comercial de impago está pendiente
 * (ESPECIFICACIÓN_MAESTRA §2), pero el modelo la soporta desde hoy. Tener
 * `ReadOnly` desde el día uno es lo que evita rehacer el middleware el día que se
 * decida esa política.
 *
 * Es el middleware de contexto quien traduce el estado a permiso de operación; el
 * enum sólo declara qué significa cada uno.
 */
enum TenantStatus: string
{
    /** Creado por super admin, sin configurar: sólo entra el propietario. */
    case PendingActivation = 'pending_activation';

    /** Operando, sin restricción. */
    case Active = 'active';

    /** Impago o suspensión administrativa: ningún acceso. */
    case Suspended = 'suspended';

    /** Impago con periodo de gracia: lectura y exportación, cero escritura. */
    case ReadOnly = 'read_only';

    /** Baja solicitada, borrado diferido: como suspendido, con fecha de purga. */
    case PendingDeletion = 'pending_deletion';

    /** Baja consumada; datos conservados por obligación legal. Sin acceso. */
    case Cancelled = 'cancelled';

    /**
     * ¿Puede alguien entrar al sistema con este tenant?
     */
    public function allowsAccess(): bool
    {
        return match ($this) {
            self::Active, self::ReadOnly, self::PendingActivation => true,
            self::Suspended, self::PendingDeletion, self::Cancelled => false,
        };
    }

    /**
     * ¿Puede escribir datos de dominio?
     *
     * `PendingActivation` sí escribe: el propietario tiene que poder configurar su
     * negocio —crear sucursales, almacenes, roles— antes de que el tenant esté
     * operando.
     */
    public function allowsWrites(): bool
    {
        return match ($this) {
            self::Active, self::PendingActivation => true,
            self::ReadOnly, self::Suspended, self::PendingDeletion, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => 'Pendiente de activación',
            self::Active => 'Activo',
            self::Suspended => 'Suspendido',
            self::ReadOnly => 'Sólo lectura',
            self::PendingDeletion => 'Baja programada',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Las transiciones de estado legales que la plataforma puede hacer hoy.
     *
     * No es la máquina completa —la baja programada y la cancelación llegarán con su propio flujo—: cubre lo que el
     * super admin hace desde el panel (activar, suspender, poner en sólo lectura, reactivar). Pasar a un estado desde
     * sí mismo NO es transición. Los estados terminales (baja, cancelado) no admiten cambio desde aquí.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::PendingActivation => in_array($target, [self::Active, self::Suspended], true),
            self::Active => in_array($target, [self::Suspended, self::ReadOnly], true),
            self::Suspended => in_array($target, [self::Active, self::ReadOnly], true),
            self::ReadOnly => in_array($target, [self::Active, self::Suspended], true),
            self::PendingDeletion, self::Cancelled => false,
        };
    }
}
