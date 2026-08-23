<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\DashboardWidget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DashboardWidget
 */
final class DashboardWidgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'report_key' => $this->report_key,
            'visualization' => $this->visualization,
            'title' => $this->title,
            'measure_key' => $this->measure_key,
            'dimension_key' => $this->dimension_key,
            'period' => $this->period,
            'top_n' => $this->top_n,
            'position' => $this->position,
        ];
    }
}
