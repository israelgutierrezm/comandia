<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un destinatario de un reporte programado (Tanda D3). Normalizado para no meter una lista JSON en dominio.
 */
final class ScheduledReportRecipient extends DomainModel
{
    protected $table = 'scheduled_report_recipients';

    public $timestamps = false;

    protected $fillable = [
        'scheduled_report_id',
        'email',
    ];

    /**
     * @return BelongsTo<ScheduledReport, $this>
     */
    public function scheduledReport(): BelongsTo
    {
        return $this->belongsTo(ScheduledReport::class, 'scheduled_report_id');
    }
}
