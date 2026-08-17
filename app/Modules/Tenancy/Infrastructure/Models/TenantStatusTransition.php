<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de estados del tenant — INMUTABLE (D75).
 *
 * Tabla propia y no la bitácora de auditoría porque la bitácora se archiva a los
 * 12 meses (D47) y esto es evidencia comercial: una disputa de cobro puede llegar
 * dos años después.
 *
 * @property TenantStatus|null $from_status
 * @property TenantStatus $to_status
 */
final class TenantStatusTransition extends DomainModel
{
    use Immutable;

    protected $table = 'tenant_status_transitions';

    protected $fillable = ['from_status', 'to_status', 'reason', 'actor_user_id'];

    protected function casts(): array
    {
        return [
            'from_status' => TenantStatus::class,
            'to_status' => TenantStatus::class,
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
     * NULL cuando la transición la hizo el sistema y no una persona.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
