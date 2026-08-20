<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Enums;

/**
 * Qué clase de papel es (§3.4 del diseño).
 */
enum PosTicketKind: string
{
    /** La comanda: el fragmento de una orden que le toca a un área. */
    case Command = 'command';

    /** La comanda de cancelación: «lo que te mandé hace diez minutos, ya no». */
    case CommandCancellation = 'command_cancellation';

    /** El ticket de cierre, o pre-cuenta: lo que el cliente revisa antes de pagar. */
    case BillPreview = 'bill_preview';

    /** El ticket final, con desglose de pagos y propina. El único que folia. */
    case FinalReceipt = 'final_receipt';

    public function label(): string
    {
        return match ($this) {
            self::Command => 'Comanda',
            self::CommandCancellation => 'Comanda de cancelación',
            self::BillPreview => 'Ticket de cierre',
            self::FinalReceipt => 'Ticket final',
        };
    }

    /** ¿Va a un área de preparación? */
    public function goesToArea(): bool
    {
        return $this === self::Command || $this === self::CommandCancellation;
    }

    /**
     * ¿Folia?
     *
     * Sólo el ticket final, porque será el folio facturable (ADR-005). Una comanda es un papel de cocina y foliarla
     * serializaría la captura por sucursal en hora pico.
     */
    public function isNumbered(): bool
    {
        return $this === self::FinalReceipt;
    }
}
