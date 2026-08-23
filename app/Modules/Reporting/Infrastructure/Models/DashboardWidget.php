<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un widget de un tablero: un reporte + una visualización + qué mostrar (D46).
 */
final class DashboardWidget extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'dashboard_widgets';

    protected $fillable = [
        'dashboard_id',
        'report_key',
        'visualization',
        'title',
        'measure_key',
        'dimension_key',
        'period',
        'top_n',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'top_n' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class, 'dashboard_id');
    }
}
