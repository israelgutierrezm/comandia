<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

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
 */
final class TerminalDevice extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'terminal_devices';

    protected $fillable = ['terminal_id', 'label'];

    /** El secreto nunca sale serializado: se entrega en claro una sola vez al crear, y de forma explícita. */
    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
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
