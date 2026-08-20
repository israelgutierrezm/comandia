<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una orden: lo que se capturó y se manda a preparar de una vez (D28).
 *
 * Una cuenta acumula N órdenes. La orden describe UN ENVÍO, y por eso no se toca cuando la cuenta se divide o se le
 * mueven items: lo que se preparó, se preparó.
 */
final class PosOrder extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_orders';

    protected $fillable = ['pos_account_id', 'sequence', 'created_by_membership_id', 'sent_at'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'pos_account_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    /**
     * @return HasMany<PosOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class, 'pos_order_id');
    }

    /** Ya salió a preparar. */
    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }
}
