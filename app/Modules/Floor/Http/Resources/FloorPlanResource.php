<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Resources;

use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FloorPlan
 */
final class FloorPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'status' => $this->status->value,

            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),

            'zones' => $this->whenLoaded('zones', fn () => $this->zones->map(fn (FloorZone $z): array => [
                'ulid' => $z->ulid,
                'name' => $z->name,
                'sort_order' => $z->sort_order,
            ])->all()),

            'tables_count' => $this->whenCounted('tables'),
        ];
    }
}
