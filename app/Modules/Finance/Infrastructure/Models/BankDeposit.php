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
 * Un depósito bancario. INMUTABLE.
 *
 * Cierra el retiro: el dinero sale de la caja con un `withdrawal` y entra al banco con esto. Editarlo cambiaría un
 * recorrido de efectivo que alguien ya concilió a mano.
 */
final class BankDeposit extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'bank_deposits';

    protected $fillable = [
        'branch_id',
        'amount',
        'bank_name',
        'reference',
        'deposited_on',
        'created_by_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'deposited_on' => 'immutable_date',
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
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }
}
