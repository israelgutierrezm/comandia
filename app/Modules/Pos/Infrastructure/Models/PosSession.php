<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Domain\Enums\PosSessionStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una sesión de caja (§6.3).
 *
 * @property PosSessionStatus $status
 */
final class PosSession extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_sessions';

    protected $fillable = [
        'branch_id',
        'terminal_id',
        'series',
        'folio',
        'status',
        'opening_float',
        'opened_by_membership_id',
        'opened_at',
        'precounted_by_membership_id',
        'precounted_at',
        'closed_by_membership_id',
        'closed_at',
        'closing_notes',
    ];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status' => PosSessionStatus::class,
            'opened_at' => 'immutable_datetime',
            'precounted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
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
     * @return BelongsTo<TenantMembership, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'opened_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'closed_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function precountedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'precounted_by_membership_id');
    }

    /**
     * @return HasMany<PosSessionDeclaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(PosSessionDeclaration::class, 'pos_session_id');
    }

    /**
     * @return HasMany<PosSessionWithdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(PosSessionWithdrawal::class, 'pos_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status->acceptsOperations();
    }

    /**
     * El folio como lo lee una persona.
     *
     * Se compone aquí y no en el cliente por la lección de D139: dos sitios formateando el mismo folio acaban imprimiendo
     * dos cosas distintas, y el número de corte es lo que un auditor cita.
     */
    public function folioNumber(): string
    {
        return sprintf('%s-%d', $this->series, $this->folio);
    }

    /**
     * ¿Ya se declaró algo en este momento del turno?
     *
     * Lo pregunta el cierre para exigir declaraciones, y el precorte para saber si se está repitiendo.
     */
    public function hasDeclarationsFor(string $moment): bool
    {
        return $this->declarations()->where('moment', $moment)->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PosSessionStatus::Open->value,
            PosSessionStatus::Precounted->value,
        ]);
    }
}
