<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Promotions;

/**
 * Una línea de la cuenta, tal como el POS se la enseña al motor de promociones — SÓLO primitivos.
 *
 * El POS pasa esto; `Promotions` lo lee y nunca toca `pos_order_items`. Es la frontera hecha dato: el motor decide con
 * lo que recibe, no consultando tablas de otro módulo (D310, D231).
 *
 * `lineTotal` es la BASE VIVA —lo que la línea vale ahora, ya restado lo que llevara— para que un porcentaje se calcule
 * sobre el precio real y dos reducciones no sumen más que la línea, igual que hace el descuento manual.
 */
final readonly class LineSnapshot
{
    public function __construct(
        public string $itemUlid,
        public int $articleId,
        public ?int $categoryId,
        public string $quantity,
        public string $unitPrice,
        public string $lineTotal,
    ) {}
}
