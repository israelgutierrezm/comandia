<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleStock
 *
 * El saldo de un artículo en un almacén.
 *
 * ## Sin ULID, y no es un olvido
 *
 * Un saldo no es una entidad que se exponga por sí misma: se identifica por el trío almacén–artículo–lote, y
 * se lee siempre a través de uno de ellos. Darle identificador propio invitaría a construir URLs sobre él, y
 * entonces cambiar la forma de la proyección rompería clientes.
 *
 * ## Sin valor monetario
 *
 * El valor del inventario depende del método de valuación (D152: último costo) y **no se guarda** en la
 * proyección para no crear una tercera fuente. Se calcula donde se necesite, con el costo vigente, y por eso
 * este recurso no lo trae: si lo trajera, cada listado de saldos pagaría una consulta de costos que casi nadie
 * mira.
 */
final class ArticleStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Como CADENA: es un DECIMAL(12,4). Y puede ser NEGATIVA — el POS nunca se bloquea por inventario
            // (§6.2), así que un saldo negativo es información y no un error de este endpoint.
            'quantity' => $this->quantity,
            'is_negative' => $this->isNegative(),

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
                'code' => $this->article->code,
                'base_unit_code' => $this->article->baseUnit?->code,
            ]),

            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
                'branch' => $this->warehouse->branch === null ? null : [
                    'ulid' => $this->warehouse->branch->ulid,
                    'name' => $this->warehouse->branch->name,
                ],
            ]),

            'lot' => $this->whenLoaded('lot', fn () => $this->lot === null ? null : [
                'ulid' => $this->lot->ulid,
                'code' => $this->lot->code,
                'expires_at' => $this->lot->expires_at?->toDateString(),
                'status' => $this->lot->status->value,
            ]),

            // El movimiento que dejó este saldo: es el testigo que permite comprobar que la proyección no se
            // desvió, sin recorrer el kardex.
            'last_movement_ulid' => $this->whenLoaded('lastMovement', fn () => $this->lastMovement?->ulid),

            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
