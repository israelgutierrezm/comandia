<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un retiro parcial de la caja (§6.3).
 *
 * **Append-only.** Un retiro es dinero que salió del cajón: si se pudiera editar, el arqueo dejaría de ser evidencia. La
 * corrección es un retiro en contra o una reversa en el diario, nunca un UPDATE.
 */
final class PosSessionWithdrawal extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'pos_session_withdrawals';

    protected $fillable = [
        'pos_session_id',
        'amount',
        'reason',
        'performed_by_membership_id',
        'authorized_by_membership_id',
    ];

    /**
     * `created_at` necesita su cast a mano porque el trait apaga los timestamps, y sin él vuelve como cadena:
     * cualquier llamada a `toIso8601String()` reventaría. Es el defecto que ya apareció en la Iteración 3.
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<PosSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'performed_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }
}
