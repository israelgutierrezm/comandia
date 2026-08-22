<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Shared\Domain\Promotions\PromotionOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La vista previa de promociones de una cuenta: lo que se descontaría al cobrar ahora.
 *
 * Envuelve el `PromotionOutcome` del motor —primitivos—. El total lo suma el SERVIDOR con bcmath y no el cliente: es
 * dinero, y sumarlo en el navegador es donde se cuelan los centavos (D134).
 *
 * @mixin PromotionOutcome
 */
final class PromotionPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $total = array_reduce(
            $this->applied,
            static fn (string $acumulado, $promo): string => bcadd($acumulado, $promo->resultingAmount, 2),
            '0.00',
        );

        return [
            'total' => $total,

            // Por línea: los tres tipos de v1 apuntan a artículos o categorías, que se materializan en líneas concretas.
            // La pantalla marca el descuento sobre cada línea que toca.
            'applied' => array_map(static fn ($promo): array => [
                'promotion_ulid' => $promo->promotionUlid,
                'name' => $promo->name,
                'item_ulid' => $promo->itemUlid,
                'amount' => $promo->resultingAmount,
            ], $this->applied),
        ];
    }
}
