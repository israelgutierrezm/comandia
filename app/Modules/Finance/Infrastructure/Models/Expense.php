<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Finance\Domain\Enums\ExpenseSource;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un gasto. INMUTABLE.
 *
 * Editarlo cambiaría un arqueo ya cerrado. Corregirlo es registrar su reversa.
 *
 * @property ExpenseSource $source
 */
final class Expense extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'expenses';

    protected $fillable = [
        'branch_id',
        'expense_category_id',
        'source',
        'pos_session_id',
        'payment_method_id',
        'amount',
        'description',
        'receipt_path',
        'created_by_membership_id',
        'authorized_by_membership_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => ExpenseSource::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /**
     * Los que salen del cajón, que son los que el arqueo tiene que conocer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFromCash(Builder $query): Builder
    {
        return $query->where('source', ExpenseSource::CashSession->value);
    }
}
