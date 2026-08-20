<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de pago de una cuenta (§6.3).
 *
 * **Append-only.** Corregir un pago es registrar su reversa, nunca editarlo: un `UPDATE` cambiaría la historia sin
 * cambiar el dinero, y el corte de anoche —ya impreso y firmado— diría otra cosa al recalcularse.
 */
final class PosPayment extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'pos_payments';

    protected $fillable = [
        'branch_id',
        'pos_account_id',
        'pos_session_id',
        'payment_method_id',
        'amount',
        'tendered_amount',
        'change_amount',
        'tip_amount',
        'tip_membership_id',
        'reference',
        'charged_by_membership_id',
        'reverses_payment_id',
        'occurred_at',
    ];

    /**
     * `created_at` necesita su cast a mano porque el trait apaga los timestamps, y sin él vuelve como cadena. Es el
     * defecto que ya apareció en la Iteración 3 y otra vez en el paso 6 de ésta.
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
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
     * @return BelongsTo<PosAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'pos_account_id');
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * A quién se le atribuye la propina de esta línea, congelado al cobrar (D233).
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function tipTo(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'tip_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function chargedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'charged_by_membership_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_payment_id !== null;
    }
}
