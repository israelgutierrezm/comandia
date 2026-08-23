<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Infrastructure\Models\ScheduledReport;
use App\Modules\Shared\Domain\Reporting\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledReport
 */
final class ScheduledReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = app(ReportRegistry::class)->get($this->report_key);

        return [
            'ulid' => $this->ulid,
            'report_key' => $this->report_key,
            'label' => $definition?->label() ?? $this->report_key,
            'format' => $this->format,
            'frequency' => $this->frequency,
            'group_by' => $this->group_by,
            'last_run_on' => $this->last_run_on?->toDateString(),
            'recipients' => $this->recipients->pluck('email')->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
