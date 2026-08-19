<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Capa 2 de identidad: la membresía usuario–tenant.
 *
 * Es **la** entidad del aislamiento de personas: la pertenencia vive aquí, no en
 * `users` (ESPECIFICACIÓN_MAESTRA §4.1).
 *
 * `user_id` puede ser NULL: el empleado sin credenciales de acceso —el lavaloza en
 * nómina que jamás inicia sesión— existe como membresía sin usuario. Por eso este
 * modelo **no** expone un `->name` propio: el nombre lo resuelve
 * `MembershipNameResolver` según la precedencia de D66.
 *
 * @property MembershipStatus $status
 * @property bool $has_all_branches
 * @property Carbon|null $pin_locked_until
 */
final class TenantMembership extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'tenant_memberships';

    protected $fillable = [
        'user_id',
        'employee_code',
        'status',
        'default_role_id',
        'has_all_branches',
        'last_active_branch_id',
    ];

    /**
     * El PIN nunca sale en una respuesta, ni siquiera su hash.
     */
    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'has_all_branches' => 'boolean',
            'pin_set_at' => 'immutable_datetime',
            'pin_failed_attempts' => 'integer',
            'pin_locked_until' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relaciones
    // -----------------------------------------------------------------

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * NULL para el empleado sin credenciales de acceso.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<EmployeeProfile, $this>
     */
    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class, 'membership_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'default_role_id');
    }

    /**
     * @return HasMany<MembershipBranchScope, $this>
     */
    public function branchScopes(): HasMany
    {
        return $this->hasMany(MembershipBranchScope::class, 'membership_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function lastActiveBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'last_active_branch_id');
    }

    // -----------------------------------------------------------------
    // Estado y credenciales
    // -----------------------------------------------------------------

    public function canOperate(): bool
    {
        return $this->status->canOperate();
    }

    /**
     * Olvida el rol activo recordado, para que la sesión nueva empiece en el rol por omisión (D234).
     *
     * ## Por qué el rol se reinicia y la sucursal no
     *
     * La sucursal activa es **dónde trabajas** y no cambia de un día para otro; el rol activo es **con
     * cuánto poder operas**, y ahí el valor seguro es el mínimo. Recordar un rol elevado
     * indefinidamente lo dejaría activo en una terminal compartida del punto de venta, que es el
     * escenario normal de una caja: quien abre por la mañana heredaría los privilegios de quien cerró.
     *
     * ## El límite de esta decisión, dicho para que nadie lo redescubra
     *
     * «Reiniciar al iniciar sesión» sólo actúa cuando alguien **vuelve a autenticarse**. Una sesión con
     * «recordarme» puede durar semanas sin pasar por el login, y en ese tiempo el rol elegido persiste.
     * Cerrar esa puerta exigiría caducidad por tiempo, que D234 no eligió — y meterla por cuenta propia
     * sería inventar una regla que nadie decidió. Queda anotado aquí, que es donde se va a leer.
     */
    public function forgetActiveRole(): void
    {
        if ($this->last_active_role_id === null) {
            return;
        }

        $this->last_active_role_id = null;

        // `saveQuietly()`: es una preferencia de navegación, no un cambio de dominio, y no debe
        // disparar los eventos del modelo. Mismo criterio que al recordarla.
        $this->saveQuietly();
    }

    public function hasCredentials(): bool
    {
        return $this->user_id !== null;
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    /**
     * ¿Está el PIN bloqueado por intentos fallidos (D54)?
     */
    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null
            && $this->pin_locked_until->isFuture();
    }

    // -----------------------------------------------------------------
    // Alcance de sucursales
    // -----------------------------------------------------------------

    /**
     * Identificadores de las sucursales en las que esta persona puede operar.
     *
     * `has_all_branches` existe para que dar de alta una sucursal nueva no excluya
     * en silencio al propietario y a los gerentes generales: "todas" significa
     * todas, incluidas las futuras.
     *
     * @return list<int>
     */
    public function scopedBranchIds(): array
    {
        if ($this->has_all_branches) {
            return Branch::query()
                ->where('status', 'active')
                ->pluck('id')
                ->map(fn (int $id): int => $id)
                ->all();
        }

        return $this->branchScopes()
            ->pluck('branch_id')
            ->map(fn (int $id): int => $id)
            ->all();
    }

    public function canOperateInBranch(int $branchId): bool
    {
        return in_array($branchId, $this->scopedBranchIds(), strict: true);
    }
}
