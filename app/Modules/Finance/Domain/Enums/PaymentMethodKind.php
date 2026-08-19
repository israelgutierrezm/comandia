<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

/**
 * Naturaleza de un método de pago.
 *
 * No es una etiqueta: cada valor implica cómo se comporta el dinero, y de eso dependen el arqueo y el corte.
 *
 * ## Por qué `custom` es un caso del enum y no la ausencia de él
 *
 * Un negocio puede aceptar vales de despensa, una aplicación de reparto o una tarjeta de regalo propia. Esos métodos
 * los define el negocio (§6.3) y no comparten comportamiento con ninguno de los cuatro del sistema — pero **sí** tienen
 * que declarar si afectan el cajón, porque el corte los suma. `custom` es «lo define el negocio», no «no se sabe».
 */
enum PaymentMethodKind: string
{
    /** Efectivo: el único que por naturaleza entra al cajón y da cambio. */
    case Cash = 'cash';

    /** Tarjeta, presente o no. Su referencia es la autorización de la terminal bancaria. */
    case Card = 'card';

    /** Transferencia o depósito. Su referencia es el folio de la operación bancaria. */
    case Transfer = 'transfer';

    /**
     * Crédito del cliente: lo que en el mostrador se llama «fiado».
     *
     * **No afecta el cajón** (§6.3) y por eso existe como naturaleza propia: cobrar a crédito deja la cuenta PAGADA y
     * el saldo cargado al cliente, que es lo que mata la «cuenta que nunca se cierra» que §6.3 prohíbe. Si fuera un
     * método `custom` cualquiera, nada garantizaría que el negocio lo configurara sin afectar caja.
     */
    case CustomerCredit = 'customer_credit';

    /** Lo que el negocio invente: vales, aplicaciones de reparto, tarjeta de regalo. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Transfer => 'Transferencia',
            self::CustomerCredit => 'Crédito del cliente',
            self::Custom => 'Otro',
        };
    }

    /**
     * ¿Es una de las naturalezas que el sistema siembra y administra?
     *
     * Las cuatro primeras nacen con el negocio y no se borran ni se renombran: son la referencia compartida con los
     * cortes, los reportes y —cuando llegue— la integración con la pasarela. Un negocio que quiera otro nombre crea un
     * método propio, que es el mismo criterio que la Iteración 3 aplicó a los motivos de merma de sistema (D186).
     */
    public function isSystem(): bool
    {
        return $this !== self::Custom;
    }
}
