<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Resources;

use App\Modules\Promotions\Infrastructure\Models\Promotion;
use App\Modules\Promotions\Infrastructure\Models\PromotionBranch;
use App\Modules\Promotions\Infrastructure\Models\PromotionTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
final class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            'percent_value' => $this->percent_value,
            'amount_value' => $this->amount_value,
            'buy_quantity' => $this->buy_quantity,
            'pay_quantity' => $this->pay_quantity,

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'daily_start' => $this->daily_start,
            'daily_end' => $this->daily_end,

            // La máscara vuelve al cliente como lista de días, la misma forma en que la recibió.
            'weekdays' => $this->weekdaysFromMask(),

            'all_branches' => $this->all_branches,
            'priority' => $this->priority,
            'is_stackable' => $this->is_stackable,
            'status' => $this->status->value,
            'version' => $this->version,

            // Objetivos y sucursales vuelven por ULID, NUNCA por id interno (D3): así el cliente los reedita sin remapear,
            // y no se filtra un id secuencial. Exige cargar las relaciones anidadas (lo hace el controlador) — sin eso, un
            // acceso perezoso reventaría, que en este proyecto es un error a propósito.
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(
                fn (PromotionBranch $b): array => ['branch_ulid' => $b->branch?->ulid],
            )->all()),

            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(
                fn (PromotionTarget $t): array => [
                    'article_ulid' => $t->article?->ulid,
                    'category_ulid' => $t->category?->ulid,
                ],
            )->all()),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<int>
     */
    private function weekdaysFromMask(): array
    {
        $days = [];

        for ($d = 0; $d <= 6; $d++) {
            if (($this->weekday_mask & (1 << $d)) !== 0) {
                $days[] = $d;
            }
        }

        return $days;
    }
}
