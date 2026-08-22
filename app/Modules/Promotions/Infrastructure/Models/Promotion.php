<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Promotions\Domain\Enums\PromotionType;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La definición de una promoción (§6.3, D50).
 *
 * @property PromotionType $type
 * @property int $weekday_mask
 */
final class Promotion extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'promotions';

    protected $fillable = [
        'name',
        'type',
        'percent_value',
        'amount_value',
        'buy_quantity',
        'pay_quantity',
        'starts_on',
        'ends_on',
        'daily_start',
        'daily_end',
        'weekday_mask',
        'all_branches',
        'priority',
        'is_stackable',
        'status',
        'created_by_membership_id',
    ];

    protected $attributes = [
        'status' => 'active',
        'all_branches' => true,
        'weekday_mask' => 127,
        'priority' => 0,
        'is_stackable' => false,
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'status' => OperationalStatus::class,
            'all_branches' => 'boolean',
            'is_stackable' => 'boolean',
            'weekday_mask' => 'integer',
            'priority' => 'integer',
            'version' => 'integer',

            'starts_on' => 'date',
            'ends_on' => 'date',

            // Montos como cadena (DECIMAL): el punto flotante no da el mismo centavo. Igual que el dinero en todo el
            // proyecto.
            'percent_value' => 'string',
            'amount_value' => 'string',
        ];
    }

    /**
     * @return HasMany<PromotionTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    /**
     * @return HasMany<PromotionBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(PromotionBranch::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
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
