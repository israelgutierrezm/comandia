<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticlePurchasePresentation
 */
final class ArticlePresentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,

            // Como cadena y no como número: es un DECIMAL(12,4) y es el divisor del costo unitario.
            // Convertirlo a float en el JSON metería error en la única operación donde importa.
            'quantity_in_base_unit' => $this->quantity_in_base_unit,

            'barcode' => $this->barcode,
            'is_default' => $this->is_default,
            'status' => $this->status->value,
        ];
    }
}
