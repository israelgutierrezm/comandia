<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una exportación de reporte encolada (Tanda B). Avanza pending → ready | failed; la pantalla la consulta y descarga.
 *
 * No guarda los parámetros del reporte: viajan en el payload del job. Aquí sólo vive el resultado.
 */
final class ReportExport extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'report_exports';

    protected $fillable = [
        'membership_id',
        'report_key',
        'format',
        'status',
        'label',
        'file_path',
        'row_count',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'immutable_datetime',
            'row_count' => 'integer',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
