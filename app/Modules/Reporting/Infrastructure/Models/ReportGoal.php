<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;

/**
 * Una meta de reporte (D46). El semáforo la compara contra el valor real del motor.
 */
final class ReportGoal extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'report_goals';

    protected $fillable = [
        'report_key',
        'measure_key',
        'branch_id',
        'period',
        'target_value',
        'direction',
    ];
}
