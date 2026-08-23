<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una vista guardada de reporte, del autor. Sus parámetros viven normalizados en `params` (sin JSON).
 */
final class SavedReportView extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'saved_report_views';

    protected $fillable = [
        'membership_id',
        'report_key',
        'name',
    ];

    /**
     * @return HasMany<SavedReportViewParam, $this>
     */
    public function params(): HasMany
    {
        return $this->hasMany(SavedReportViewParam::class, 'saved_report_view_id');
    }
}
