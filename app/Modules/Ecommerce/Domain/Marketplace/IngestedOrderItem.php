<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Marketplace;

/**
 * Una línea de un pedido de marketplace, ya normalizada por el adaptador del canal (ADR-015). El
 * `externalItemId` se resuelve al artículo por el mapeo de menú; el precio lo pone Comandia, no la
 * plataforma (el catálogo es la fuente de verdad).
 */
final readonly class IngestedOrderItem
{
    public function __construct(
        public string $externalItemId,
        public int $quantity,
        public ?string $notes = null,
    ) {}
}
