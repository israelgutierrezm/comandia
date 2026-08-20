<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Pos\Domain\Enums\PosAccountOperationKind;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Qué se movió, de dónde a dónde y quién. INMUTABLE.
 *
 * @property PosAccountOperationKind $kind
 */
final class PosAccountOperation extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'pos_account_operations';

    protected $fillable = [
        'kind',
        'source_account_id',
        'target_account_id',
        'performed_by_membership_id',
        'authorized_by_membership_id',
        'detail_count',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PosAccountOperationKind::class,
            'detail_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'source_account_id');
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'target_account_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'performed_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /**
     * @return HasMany<PosAccountOperationItem, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(PosAccountOperationItem::class, 'operation_id');
    }
}
