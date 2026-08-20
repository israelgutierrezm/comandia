<?php

declare(strict_types=1);

namespace App\Modules\Printing\Domain\Enums;

/**
 * En qué va un trabajo de impresión.
 *
 * `pending → claimed → printed | failed`, y `cancelled` desde los dos primeros.
 *
 * ## Por qué existe `claimed` y no se pasa de pendiente a impreso
 *
 * Porque puede haber varios agentes en la misma sucursal —una tableta y una computadora— y sin un estado intermedio los
 * dos tomarían el mismo trabajo y la cocina recibiría el papel dos veces. `claimed` es lo que hace que reclamar sea
 * exclusivo: se marca con lock, y a partir de ahí el trabajo tiene dueño.
 *
 * Y contesta otra pregunta que sólo aparece en producción: un trabajo reclamado que nunca llega a `printed` significa
 * que el agente lo tomó y se murió. Sin `claimed` eso se vería como «sigue pendiente» y nadie sabría que hay una
 * computadora colgada.
 */
enum PrintJobStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Printed = 'printed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Claimed => 'Tomado por un agente',
            self::Printed => 'Impreso',
            self::Failed => 'Falló',
            self::Cancelled => 'Cancelado',
        };
    }

    /** ¿Ya terminó, para bien o para mal? */
    public function isFinal(): bool
    {
        return $this === self::Printed || $this === self::Cancelled;
    }

    /**
     * ¿Se puede devolver a la cola?
     *
     * Desde `failed` —ya hay papel en la impresora— y desde `claimed` —el agente lo tomó y se murió—. Los dos son el
     * mismo hecho visto desde la cola: nadie lo está imprimiendo y hay que volver a repartirlo.
     */
    public function canRequeue(): bool
    {
        return $this === self::Failed || $this === self::Claimed;
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Claimed, self::Cancelled],

            // De `claimed` se puede volver a `pending`: es lo que pasa cuando un agente muere con el trabajo en la mano
            // y alguien lo libera para que otro lo tome. Sin ese camino, un agente colgado dejaría comandas atrapadas
            // hasta que alguien las volviera a mandar a mano.
            self::Claimed => [self::Printed, self::Failed, self::Pending, self::Cancelled],

            // Un fallo se reintenta: la impresora se quedó sin papel y alguien lo puso.
            self::Failed => [self::Pending, self::Cancelled],

            self::Printed, self::Cancelled => [],
        };
    }
}
