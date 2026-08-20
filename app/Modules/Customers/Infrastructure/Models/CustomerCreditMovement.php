<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento del saldo de un cliente. INMUTABLE.
 *
 * Corregir es un `adjustment` en contra, nunca un UPDATE: el estado de cuenta que el cliente ya vio no puede cambiar de
 * contenido.
 *
 * @property CreditMovementType $type
 */
final class CustomerCreditMovement extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'customer_credit_movements';

    protected $fillable = [
        'customer_id',
        'type',
        'amount',
        'balance_after',
        'source_type',
        'source_ulid',
        'pos_session_id',
        'created_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditMovementType::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }
}
