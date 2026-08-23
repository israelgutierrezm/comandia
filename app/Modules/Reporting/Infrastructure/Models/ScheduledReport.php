<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un reporte programado (Tanda D3). Corre con el alcance de su autor y envía al periodo cerrado según su frecuencia.
 */
final class ScheduledReport extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'scheduled_reports';

    protected $fillable = [
        'membership_id',
        'report_key',
        'format',
        'frequency',
        'group_by',
        'last_run_on',
    ];

    protected function casts(): array
    {
        return [
            'last_run_on' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<ScheduledReportRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(ScheduledReportRecipient::class, 'scheduled_report_id');
    }
}
