<?php

declare(strict_types=1);

namespace App\Modules\Printing\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El proceso que convierte trabajos en papel.
 *
 * No es un usuario: no tiene rol activo, no tiene permisos y no opera nada. Ver la migración.
 */
final class PrintAgent extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'print_agents';

    protected $fillable = [
        'branch_id',
        'name',
        'token_hash',
        'last_seen_at',
        'status',
    ];

    /**
     * El hash nunca sale del servidor, ni siquiera por accidente en un `dd()`.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
