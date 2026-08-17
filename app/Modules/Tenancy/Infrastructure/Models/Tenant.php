<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * El tenant: la raíz del aislamiento.
 *
 * EXCEPCIÓN DECLARADA al global scope de tenant (ADR-002, §1 del diseño): extiende
 * `Model` y no `DomainModel` porque su PK **es** el `tenant_id`. Acotarse a sí
 * mismo no significa nada.
 *
 * Consecuencia que hay que tener presente: **este modelo no está protegido por el
 * scope**. Cualquier consulta sobre `Tenant` es potencialmente cross-tenant, así
 * que sólo debe usarse desde el middleware de resolución de contexto y desde el
 * módulo de super admin. El código de dominio no consulta tenants: recibe el
 * contexto.
 *
 * @property-read int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $legal_name
 * @property string $slug
 * @property TenantStatus $status
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasPublicUlid;

    protected $table = 'tenants';

    protected $guarded = ['id', 'ulid'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'onboarded_at' => 'immutable_datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    /**
     * @return HasMany<TenantStatusTransition, $this>
     */
    public function statusTransitions(): HasMany
    {
        return $this->hasMany(TenantStatusTransition::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active');
    }

    /**
     * @return HasMany<TenantLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(TenantLimit::class);
    }

    /**
     * @return HasMany<TenantModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    // -----------------------------------------------------------------
    // Estado
    // -----------------------------------------------------------------

    public function allowsAccess(): bool
    {
        return $this->status->allowsAccess();
    }

    public function allowsWrites(): bool
    {
        return $this->status->allowsWrites();
    }
}
