<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Marketplace;

/**
 * Un pedido de marketplace ya NORMALIZADO por el adaptador del canal (ADR-015): la forma común a la que
 * cada plataforma (DiDi/Uber/Rappi) traduce su payload propio. La ingesta trabaja sólo con esto, no con
 * el formato de cada quien.
 */
final readonly class IngestedOrder
{
    /**
     * @param  list<IngestedOrderItem>  $items
     */
    public function __construct(
        public string $externalOrderId,
        public string $customerName,
        public array $items,
        public ?string $externalStoreId = null,
        public ?string $customerPhone = null,
        public ?string $notes = null,
        public ?string $reportedTotal = null,
    ) {}
}
