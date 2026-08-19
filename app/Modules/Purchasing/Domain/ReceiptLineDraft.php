<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Domain;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;

/**
 * Un renglón de factura ya resuelto y convertido, listo para escribirse.
 *
 * Existe para que el servicio no reciba un arreglo sin forma: los seis primeros campos son lo que dice la factura, y
 * `quantityInBaseUnit` es la conversión ya hecha — que se calcula en la frontera HTTP porque depende de la presentación,
 * y se **congela** aquí porque la presentación puede darse de baja mientras el movimiento tiene que seguir cuadrando.
 */
final readonly class ReceiptLineDraft
{
    /**
     * @param  numeric-string  $quantity  como se capturó, en la unidad de la presentación
     * @param  numeric-string  $quantityInBaseUnit  convertida
     * @param  numeric-string  $unitPrice  SIN IVA, por unidad de captura
     * @param  numeric-string  $taxRate  la tasa de esta línea, tal como la dice la factura
     * @param  int|null  $reversedLotId  sólo en una reversa: el lote que creó la recepción original
     */
    public function __construct(
        public Article $article,
        public ?ArticlePurchasePresentation $presentation,
        public string $quantity,
        public string $quantityInBaseUnit,
        public string $unitPrice,
        public string $taxRate,
        public ?string $lotCode = null,
        public ?string $expiresAt = null,
        public ?int $reversedLotId = null,
    ) {}
}
