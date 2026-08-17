<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Límite medible del tenant, fijado por el super admin.
 *
 * `limit_value` NULL significa **sin límite**, no cero. Es la distinción que evita
 * que un tenant enterprise sin topes quede accidentalmente bloqueado.
 *
 * @property TenantLimitKey $limit_key
 * @property int|null $limit_value
 */
final class TenantLimit extends DomainModel
{
    protected $table = 'tenant_limits';

    protected $fillable = ['limit_key', 'limit_value'];

    protected function casts(): array
    {
        return [
            'limit_key' => TenantLimitKey::class,
            'limit_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUnlimited(): bool
    {
        return $this->limit_value === null;
    }

    /**
     * ¿El uso indicado cabe dentro de este límite?
     */
    public function allows(int $currentUsage): bool
    {
        return $this->isUnlimited() || $currentUsage < $this->limit_value;
    }
}
