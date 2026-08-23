<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un parámetro de una vista guardada: un par (nombre, valor) tal como viaja en la query del reporte. Normalizado a
 * propósito para no meter JSON en dominio.
 */
final class SavedReportViewParam extends DomainModel
{
    protected $table = 'saved_report_view_params';

    public $timestamps = false;

    protected $fillable = [
        'saved_report_view_id',
        'name',
        'value',
    ];

    /**
     * @return BelongsTo<SavedReportView, $this>
     */
    public function view(): BelongsTo
    {
        return $this->belongsTo(SavedReportView::class, 'saved_report_view_id');
    }
}
