<?php

declare(strict_types=1);

namespace App\Modules\Printing\Domain\Enums;

/**
 * Qué clase de trabajo es.
 */
enum PrintJobKind: string
{
    case Ticket = 'ticket';

    /**
     * Abrir el cajón de dinero.
     *
     * Es un trabajo de impresión porque el cajón se abre mandándole una secuencia a la impresora de tickets: no tiene
     * cable propio. Modelarlo como otra cosa obligaría a un segundo canal hacia el mismo agente y la misma impresora.
     */
    case DrawerOpen = 'drawer_open';

    public function label(): string
    {
        return match ($this) {
            self::Ticket => 'Ticket',
            self::DrawerOpen => 'Apertura de cajón',
        };
    }
}
