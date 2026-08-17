<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Domain\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suscripción del tenant (D4).
 *
 * Sin montos: el cobro real llega al final del proyecto y meter precios hoy sería
 * inventar la forma comercial. La entidad existe desde el día uno porque es lo que
 * la arquitectura necesita para medir y limitar.
 *
 * @property SubscriptionStatus $status
 */
final class Subscription extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'subscriptions';

    protected $fillable = [
        'status',
        'started_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_at' => 'immutable_date',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'cancelled_at' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }
}
