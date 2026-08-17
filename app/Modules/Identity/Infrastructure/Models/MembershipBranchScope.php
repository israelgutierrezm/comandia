<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alcance de sucursales de una membresía (D12).
 *
 * Sin ULID: es una tabla de relación y no se expone como recurso propio de la API.
 *
 * Sin `updated_at`: una fila de alcance se crea o se borra, no se edita —cambiar
 * `branch_id` sería otra fila—. Por eso la migración declara sólo `created_at`.
 */
final class MembershipBranchScope extends DomainModel
{
    protected $table = 'membership_branch_scopes';

    protected $fillable = ['membership_id', 'branch_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
