<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * En qué va un pedido para llevar (§6.3).
 *
 * `pending → ready → delivered`, y no hay vuelta atrás desde `delivered`: entregar es un hecho físico —la bolsa salió
 * por el mostrador— y deshacerlo en el sistema no la trae de vuelta. Si se entregó al cliente equivocado, lo que hay es
 * un problema nuevo, no un estado anterior.
 */
enum TakeoutDeliveryStatus: string
{
    /** Se ordenó; la cocina lo está haciendo. */
    case Pending = 'pending';

    /** Listo en el mostrador: es cuando se grita el número. */
    case Ready = 'ready';

    /** Se lo llevaron. */
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Ready => 'Listo',
            self::Delivered => 'Entregado',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // De pendiente se puede saltar directo a entregado: el cliente estaba esperando de pie y se lo dieron en
            // cuanto salió. Obligar a pasar por «listo» sería un toque de más en el momento de más prisa.
            self::Pending => [self::Ready, self::Delivered],

            self::Ready => [self::Delivered],
            self::Delivered => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), strict: true);
    }
}
