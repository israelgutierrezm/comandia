<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Reporting\Infrastructure\Models\ReportGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportGoal
 */
final class ReportGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'report_key' => $this->report_key,
            'measure_key' => $this->measure_key,
            // El ULID de la sucursal —nunca el id interno (D3)—, o null si la meta es consolidada.
            'branch_ulid' => $this->branch_id === null ? null : Branch::query()->whereKey($this->branch_id)->value('ulid'),
            'period' => $this->period,
            'target_value' => $this->target_value,
            'direction' => $this->direction,
        ];
    }
}
