<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que el cajero DECLARA que hay, por método de pago.
 *
 * No lleva el esperado ni la diferencia: los dos se calculan del diario al vuelo (§6.5, ADR-004). Guardarlos sería la
 * verdad paralela que ADR-004 prohíbe, y quedarían desactualizados en cuanto se asentara un movimiento más.
 *
 * Y **sí se puede editar**, al contrario que un retiro: mientras el arqueo no ha ocurrido, corregir un dedazo de conteo
 * no está borrando evidencia — está contando otra vez, que es lo que se espera de alguien que cuenta dinero.
 */
final class PosSessionDeclaration extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_session_declarations';

    protected $fillable = [
        'pos_session_id',
        'moment',
        'payment_method_id',
        'declared_amount',
        'declared_by_membership_id',
        'declared_at',
    ];

    protected function casts(): array
    {
        return ['declared_at' => 'immutable_datetime'];
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
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'declared_by_membership_id');
    }
}
