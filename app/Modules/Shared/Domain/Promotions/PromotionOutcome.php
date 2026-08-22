<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Promotions;

/**
 * Lo que el motor devuelve para una cuenta: la lista de promociones aplicadas por línea.
 *
 * Vacía si ninguna aplica —o si el motor no está disponible y el POS cae al null-object—, que es el caso seguro: el
 * POS cobra sin promoción antes que no cobrar.
 */
final readonly class PromotionOutcome
{
    /**
     * @param  list<AppliedPromotion>  $applied
     */
    public function __construct(public array $applied = []) {}

    public function isEmpty(): bool
    {
        return $this->applied === [];
    }
}
