<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseReceiptLine
 *
 * Un renglón de la factura.
 *
 * Viajan las **dos cantidades**: la capturada («3 cajas») y la convertida («36 000 g»). La primera es lo que dice la
 * factura y la segunda lo que entró al inventario, y la primera explica la segunda — que es lo que alguien necesita
 * cuando el saldo no le cuadra con el papel.
 */
final class PurchaseReceiptLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'article' => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'base_unit_code' => $this->article->baseUnit?->code,
            ],

            'presentation' => $this->presentation === null ? null : [
                'ulid' => $this->presentation->ulid,
                'name' => $this->presentation->name,
                'quantity_in_base_unit' => $this->presentation->quantity_in_base_unit,
            ],

            'quantity' => $this->quantity,
            'quantity_in_base_unit' => $this->quantity_in_base_unit,

            // Sin IVA, por unidad de captura: es el renglón de la factura tal cual.
            'unit_price' => $this->unit_price,
            'tax_rate' => $this->tax_rate,

            'line_subtotal' => $this->line_subtotal,
            'line_tax' => $this->line_tax,
            'line_total' => $this->line_total,

            // El lote **como se capturó**. No se resuelve el `article_lots` por relación: eso obligaría a `Purchasing` a
            // importar modelos de `Inventory` y el grafo de módulos quedaría con un ciclo (ver `PurchaseReceiptLine`).
            'lot_code' => $this->lot_code,
            'expires_at' => $this->expires_at?->toDateString(),

            // ¿Llegó al kardex? Es la pregunta que importa, y hace DETECTABLE una confirmación interrumpida: un renglón
            // con cantidad y sin movimiento es una confirmación que se quedó a medias.
            'was_applied' => $this->wasApplied(),
        ];
    }
}
