<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Resources;

use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosOrderItemModifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosOrderItem
 *
 * La línea que se cobra. Todo lo monetario que publica está CONGELADO en la fila — no se lee del catálogo.
 */
final class PosOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            // La orden a la que pertenece la línea.
            //
            // Sin esto, la pantalla no puede saber QUÉ orden comandar y tenía que adivinar: elegía «la primera
            // orden sin enviar», que con dos órdenes abiertas es siempre la misma. Capturar después de comandar
            // crea una orden nueva, así que lo capturado después se quedaba sin salir a la cocina — y en la
            // pantalla se veía «Capturado» para siempre, sin ningún error.
            'order_ulid' => $this->order?->ulid,


            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'was_commanded' => $this->wasCommanded(),

            'quantity' => $this->quantity,

            // El nombre CONGELADO, no el del artículo hoy: un ticket reimpreso un mes después tiene que decir lo que
            // decía el original.
            'article_name' => $this->article_name,

            'unit_price' => $this->unit_price,
            'vat_rate' => $this->vat_rate,
            'modifiers_total' => $this->modifiers_total,
            'discount_amount' => $this->discount_amount,

            // Calculado por la BASE como columna generada: es una multiplicación de dinero, y hacerla en dos sitios es
            // como se desincronizan los totales (D134).
            'line_total' => $this->line_total,

            // El IVA CONTENIDO en la línea. Los precios son IVA incluido (D30), así que se extrae en lugar de sumarse —
            // sumar la tasa daría un impuesto mayor del real y un desglose que no cuadra con lo que el cliente pagó.
            'vat_amount' => $this->vatAmount(),

            'is_courtesy' => $this->is_courtesy,

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
            ]),

            'preparation_area' => $this->whenLoaded('preparationArea', fn () => $this->preparationArea === null ? null : [
                'ulid' => $this->preparationArea->ulid,
                'name' => $this->preparationArea->name,
            ]),

            'modifiers' => $this->whenLoaded(
                'modifiers',
                fn () => $this->modifiers->map(fn (PosOrderItemModifier $m): array => [
                    'ulid' => $m->ulid,
                    'name' => $m->modifier_name,
                    'extra_price' => $m->extra_price,
                    'quantity' => $m->quantity,
                    'total' => $m->total(),
                ])->all(),
            ),

            'cancelled_reason' => $this->cancelled_reason,
            'cancellation_destination' => $this->cancellation_destination,
        ];
    }
}
