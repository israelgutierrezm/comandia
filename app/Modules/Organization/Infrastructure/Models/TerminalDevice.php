<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo enrolado a una terminal COMPARTIDA (ADR-012).
 *
 * Es una credencial de DISPOSITIVO, sin usuario: el navegador guarda el secreto —mostrado una sola
 * vez al enrolar— y al arrancar lo canjea por una sesión de dispositivo. Sobre esa sesión, cada
 * mesero teclea su PIN para abrir su sesión de operación. El secreto vive hasheado y se revoca por
 * fila (aparato perdido) sin tocar la terminal.
 *
 * ## Camino de TOKEN (ADR-014)
 *
 * La app móvil no tiene cookies: canjea el mismo secreto por un **token de dispositivo** (`token_hash`,
 * hasheado como el del agente de impresión) y, como no hay sesión donde guardar al operador, la capa de
 * operador se persiste aquí (`operator_membership_id` + `operator_last_activity_at`). Un operador a la
 * vez por dispositivo. El flujo web por cookie no usa estas columnas.
 */
final class TerminalDevice extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'terminal_devices';

    protected $fillable = ['terminal_id', 'label'];

    /** Ni el secreto ni el token salen serializados: se entregan en claro una sola vez, de forma explícita. */
    protected $hidden = ['secret_hash', 'token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'operator_last_activity_at' => 'immutable_datetime',
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
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * La membresía que opera ahora este dispositivo por token (camino móvil, ADR-014). Nula = en el bloqueo.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function operatorMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'operator_membership_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Revoca el dispositivo (aparato perdido/robado). Idempotente: si ya estaba revocado, conserva la
     * fecha original —no se «re-revoca»—. Un dispositivo revocado deja de poder canjear sesión de
     * inmediato (lo comprueba {@see \App\Modules\Identity\Http\Controllers\SharedTerminalController}).
     *
     * Además invalida el token (camino móvil, ADR-014) y olvida al operador: revocar deja al aparato
     * fuera al instante, tanto por cookie como por token.
     */
    public function revoke(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->forceFill([
            'revoked_at' => now(),
            'token_hash' => null,
            'operator_membership_id' => null,
            'operator_last_activity_at' => null,
        ])->save();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Telemetría de conectividad: cuándo canjeó por última vez. Sin tocar `updated_at` ni disparar
     * auditoría, igual que `Terminal::touchLastSeen()`.
     */
    public function touchLastSeen(): void
    {
        $this->newQuery()->whereKey($this->getKey())->update(['last_seen_at' => now()]);
    }
}
