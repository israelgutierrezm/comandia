<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain\Enums;

/**
 * De dónde salió una observación de precio (D26).
 *
 * Los tres tienen distinta fuerza como evidencia, y por eso son tres y no una bandera de «automático»:
 *
 *   - `receipt` es un hecho: la mercancía llegó y esto se pagó.
 *   - `quote` es una promesa: lo que el proveedor dijo por teléfono, útil para decidir antes de comprar.
 *   - `manual` es lo que alguien vio en una lista de precios y capturó.
 *
 * Al comparar, un `receipt` vale más que un `quote`, y sin la distinción no habría forma de saber si el precio con el
 * que se está negociando alguna vez se cobró de verdad.
 */
enum SupplierPriceSource: string
{
    /** Salió de una recepción confirmada. Lo escribe el sistema. */
    case Receipt = 'receipt';

    /** Cotización: el proveedor lo ofreció, todavía no se compró a ese precio. */
    case Quote = 'quote';

    /** Capturado a mano de una lista de precios. */
    case Manual = 'manual';

    /** ¿Es un precio efectivamente pagado? */
    public function isConfirmedPurchase(): bool
    {
        return $this === self::Receipt;
    }

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Recepción',
            self::Quote => 'Cotización',
            self::Manual => 'Captura manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /** Las que una persona puede capturar. `receipt` la escribe el sistema al confirmar una recepción. */
    public function isCapturableByHand(): bool
    {
        return $this !== self::Receipt;
    }
}
