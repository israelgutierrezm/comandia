<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un asiento del diario financiero (ADR-004).
 *
 * **Append-only.** El trait cierra las tres vías de escritura destructiva, incluida la del query builder, que no dispara
 * eventos y sería la puerta más ancha.
 *
 * @property FinancialMovementType $type
 */
final class FinancialMovement extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'financial_movements';

    /**
     * Sin `updated_at`: el trait apaga `$timestamps`.
     *
     * Y por eso `created_at` necesita su cast a mano — es la lección de la Iteración 3: con los timestamps apagados,
     * Eloquent deja de castear la columna y `created_at` vuelve como cadena, así que cualquier `->toIso8601String()`
     * reventaba.
     */
    protected $fillable = [
        'branch_id',
        'type',
        'pos_session_id',
        'payment_method_id',
        'affects_cash_drawer',
        'amount',
        'source_type',
        'source_ulid',
        'actor_membership_id',
        'reverses_movement_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinancialMovementType::class,
            'affects_cash_drawer' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    /**
     * El movimiento que este corrige.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /**
     * ¿Es una corrección de otro asiento?
     *
     * Se pregunta por el ENLACE y no por el tipo, y la distinción importa: la reversa de un pago se asienta con tipo
     * `payment` en negativo —para que el corte la sume donde toca— y su naturaleza de corrección la lleva el enlace. Un
     * tipo `reversal` suelto serviría para lo que no encaja en ningún otro, pero no es lo que define una reversa.
     */
    public function isReversal(): bool
    {
        return $this->reverses_movement_id !== null;
    }

    /**
     * Los que mueven el cajón, para el arqueo.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAffectingCashDrawer(Builder $query): Builder
    {
        return $query->where('affects_cash_drawer', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForSession(Builder $query, int $sessionId): Builder
    {
        return $query->where('pos_session_id', $sessionId);
    }
}
