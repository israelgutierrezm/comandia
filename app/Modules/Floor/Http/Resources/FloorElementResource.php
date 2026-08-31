<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Resources;

use App\Modules\Floor\Infrastructure\Models\FloorElement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FloorElement
 */
final class FloorElementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'kind' => $this->kind,
            'text' => $this->text,

            // La misma forma de geometría que la mesa (ADR-003), sin `shape`: el `kind` ya dice cómo se dibuja.
            'geometry' => [
                'x' => $this->x,
                'y' => $this->y,
                'width' => $this->width,
                'height' => $this->height,
                'rotation' => $this->rotation,
            ],

            'sort_order' => $this->sort_order,
        ];
    }
}
