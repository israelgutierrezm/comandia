<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ArticleLot
 *
 * Un lote con su caducidad. `expires_at` en `null` significa **no caduca** —la sal, el azúcar— y es distinto de
 * una fecha muy lejana: poner el año 2099 sería inventar un dato que alguien leería como real.
 */
final class ArticleLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,

            'expires_at' => $this->expires_at?->toDateString(),
            'received_at' => $this->received_at?->toDateString(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'can_be_issued' => $this->status->canBeIssued(),

            // Ya caducó a día de hoy. Se calcula en el SERVIDOR y no en el cliente porque depende de un reloj:
            // dos navegadores mal ajustados darían respuestas distintas sobre el mismo lote, y uno de los dos
            // dejaría surtir mercancía vencida.
            'is_expired' => $this->hasExpiredBy(now()),

            'article' => $this->whenLoaded('article', fn () => [
                'ulid' => $this->article->ulid,
                'name' => $this->article->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
