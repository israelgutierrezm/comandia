<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una propina entregada. INMUTABLE.
 *
 * Es dinero que salió del cajón hacia el bolsillo de alguien. Editarlo cambiaría un arqueo ya cerrado y, peor, cambiaría
 * cuánto se le debe a una persona sin que ella se entere.
 */
final class TipSettlement extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'tip_settlements';

    protected $fillable = [
        'branch_id',
        'pos_session_id',
        'membership_id',
        'amount',
        'paid_by_membership_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * A quién se le pagó.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /**
     * Quién se lo entregó. Son dos personas y por eso son dos columnas.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'paid_by_membership_id');
    }
}
