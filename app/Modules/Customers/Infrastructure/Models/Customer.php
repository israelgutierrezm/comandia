<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un cliente. El mínimo que el cobro necesita (D235).
 */
final class Customer extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'customers';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'notes',
        'status',
        'created_by_membership_id',
    ];

    /**
     * @return HasOne<CustomerCredit, $this>
     */
    public function credit(): HasOne
    {
        return $this->hasOne(CustomerCredit::class);
    }

    /**
     * @return HasMany<CustomerCreditMovement, $this>
     */
    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
