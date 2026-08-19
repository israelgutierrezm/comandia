<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\TransferLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TransferLine
 *
 * Un renglón con sus tres cantidades, que juntas contestan «¿se pidió poco, se mandó poco o se perdió en el camino?».
 *
 * Las tres viajan siempre, incluso en `null`. Un `null` en `shipped_quantity` significa «todavía no se envía» y es lo
 * que la UI necesita para saber si pintar un campo de captura o un dato — omitir la clave la obligaría a deducirlo
 * del estado del documento, que es la clase de deducción que se desincroniza.
 */
final class TransferLineResource extends JsonResource
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

            'lot' => $this->lot === null ? null : [
                'ulid' => $this->lot->ulid,
                'code' => $this->lot->code,
                'expires_at' => $this->lot->expires_at?->toDateString(),
            ],

            'requested_quantity' => $this->requested_quantity,
            'shipped_quantity' => $this->shipped_quantity,
            'received_quantity' => $this->received_quantity,

            // Lo que salió y no llegó, calculado por la base. `null` mientras no se reciba: «todavía no se sabe».
            'transit_difference' => $this->transit_difference,
        ];
    }
}
