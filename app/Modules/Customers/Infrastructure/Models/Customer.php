<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un cliente. El mínimo que el cobro necesita (D235).
 *
 * Desde la Iteración 8 (Tanda C) es **autenticable**: puede iniciar sesión en la tienda en línea con su correo y
 * contraseña (guard `customer`, aparte del de personal). Un cliente sin credenciales —el alta express del POS (D43)— sigue
 * existiendo; sólo no puede entrar a la tienda hasta registrarse.
 */
final class Customer extends DomainModel implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use HasPublicUlid;

    protected $table = 'customers';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'password',
        'birthday',
        'notes',
        'status',
        'created_by_membership_id',
    ];

    /**
     * La contraseña nunca se serializa: no sale por ningún Resource ni en logs.
     *
     * @var list<string>
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            // Al asignar la contraseña se hashea sola; nunca se guarda en claro.
            'password' => 'hashed',
        ];
    }

    /** ¿Tiene credenciales para entrar a la tienda? */
    public function hasCredentials(): bool
    {
        return $this->password !== null;
    }

    // ---------------------------------------------------------------------
    // Sin «recordarme»: la sesión de cliente es sólo de sesión (más seguro para una tienda pública). Se anulan los
    // métodos del trait para no exigir una columna `remember_token` que no existe ni se usa.
    // ---------------------------------------------------------------------

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // no-op
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return HasOne<CustomerCredit, $this>
     */
    public function credit(): HasOne
    {
        return $this->hasOne(CustomerCredit::class);
    }

    /**
     * @return HasMany<CustomerFiscalProfile, $this>
     */
    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(CustomerFiscalProfile::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<CustomerCreditMovement, $this>
     */
    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
