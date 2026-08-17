<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Entrada de la bitácora técnica — INMUTABLE (§6.7, D47).
 *
 * Junto con los payloads de trabajos de impresión, la única tabla del proyecto con
 * columnas JSON permitidas.
 *
 * ## Las dos columnas de actor
 *
 * Son el corazón del control antifraude. Cuando un mesero aplica un descuento y el
 * gerente teclea su PIN para autorizarlo, `actor_membership_id` es el mesero y
 * `authorized_by_membership_id` es el gerente. Con una sola columna de actor sería
 * imposible distinguir "el gerente aplicó el descuento" de "el gerente autorizó que
 * el mesero lo aplicara", y esa distinción es exactamente lo que necesita el
 * reporte de robo hormiga (§6.3, §9).
 *
 * `active_role_id` se guarda porque con D9 el permiso efectivo depende del rol
 * activo: auditar la acción sin el rol deja la pregunta "¿podía hacerlo?" sin
 * respuesta reproducible.
 *
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
final class AuditEntry extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'audit_entries';

    protected $fillable = [
        'branch_id',
        'terminal_id',
        'actor_user_id',
        'actor_membership_id',
        'authorized_by_membership_id',
        'active_role_id',
        'action',
        'auditable_type',
        'auditable_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * La evidencia duradera de quién actuó. La FK es RESTRICT: no se puede borrar a
     * un usuario que tiene historia.
     *
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Quien ejecutó la acción: el dueño de la sesión.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    /**
     * Quien autorizó con su PIN, distinto del ejecutor.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'authorized_by_membership_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function activeRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    // -----------------------------------------------------------------
    // Consultas de investigación
    // -----------------------------------------------------------------

    /**
     * Historia completa de una entidad: el caso de uso del auditor.
     *
     * Servida por `audit_entries_tenant_auditable_index`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAuditable(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderByDesc('created_at');
    }

    /**
     * Qué hizo una persona. Servida por `audit_entries_tenant_actor_index`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByActor(Builder $query, int $membershipId): Builder
    {
        return $query
            ->where('actor_membership_id', $membershipId)
            ->orderByDesc('created_at');
    }

    /**
     * Acciones de un tipo concreto: la base del reporte de descuentos, cortesías y
     * cancelaciones que §9 exige. Servida por `audit_entries_tenant_action_index`.
     *
     * @param  list<string>  $actions
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfActions(Builder $query, array $actions): Builder
    {
        return $query
            ->whereIn('action', $actions)
            ->orderByDesc('created_at');
    }

    /**
     * ¿Fue una acción autorizada por alguien distinto de quien la ejecutó?
     */
    public function wasAuthorizedByAnother(): bool
    {
        return $this->authorized_by_membership_id !== null
            && $this->authorized_by_membership_id !== $this->actor_membership_id;
    }
}
