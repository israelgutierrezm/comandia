<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Una invitación para entrar a un negocio (diseño de acceso, fase 3).
 *
 * Vigente mientras no se haya aceptado ni cancelado y no haya vencido (7 días). El token sólo existe en claro en el
 * enlace; aquí vive su SHA-256 (`token_hash`), así que una copia de la base no entrega invitaciones utilizables.
 *
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 */
final class MembershipInvitation extends DomainModel
{
    use HasPublicUlid;

    /** Días de vigencia de una invitación. */
    public const DAYS_VALID = 7;

    protected $table = 'membership_invitations';

    protected $fillable = [
        'membership_id',
        'email',
        'token_hash',
        'invited_by_membership_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->expires_at->isFuture();
    }

    /**
     * Las que siguen esperando respuesta: ni aceptadas ni canceladas. Las vencidas entran —la ficha dice «venció» y
     * ofrece reenviar—; `isPending()` es quien distingue.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'invited_by_membership_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Los roles que la persona recibe al aceptar. El pivote lleva `tenant_id` (ADR-002), así que se adjuntan con él.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_invitation_roles', 'invitation_id', 'role_id');
    }
}
