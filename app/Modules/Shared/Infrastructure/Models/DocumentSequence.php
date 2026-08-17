<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Secuencia de foliación por (tenant, sucursal, tipo de documento, serie)
 * — ARQUITECTURA_MAESTRA §7, D73.
 *
 * Este modelo **no** expone un método para tomar un folio. Eso vive en
 * `DocumentNumberAllocator`, porque tomar un folio sin huecos requiere un
 * `SELECT ... FOR UPDATE` dentro de la transacción del documento, y un método de
 * modelo invitaría a llamarlo fuera de transacción — que es justo el error que
 * produce huecos y duplicados.
 *
 * @property string $document_type
 * @property string $series
 * @property int $next_number
 */
final class DocumentSequence extends DomainModel
{
    protected $table = 'document_sequences';

    protected $fillable = ['branch_id', 'document_type', 'series', 'next_number'];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
