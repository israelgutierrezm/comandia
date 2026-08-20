<?php

declare(strict_types=1);

namespace App\Modules\Printing\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Printing\Domain\Enums\PrintJobKind;
use App\Modules\Printing\Domain\Enums\PrintJobStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que hay que sacar por una impresora.
 *
 * @property PrintJobKind $kind
 * @property PrintJobStatus $status
 * @property array<string, mixed> $payload
 */
final class PrintJob extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'print_jobs';

    protected $fillable = [
        'branch_id',
        'kind',
        'pos_ticket_id',
        'printer_id',
        'status',
        'payload',
        'attempts',
        'claimed_by_agent',
        'claimed_at',
        'printed_at',
        'failed_at',
        'last_error',
    ];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => PrintJobKind::class,
            'status' => PrintJobStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'printed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
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
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /**
     * El ticket del que salió, si salió de uno.
     *
     * Es la única referencia de este módulo a `Pos`, y está declarada en `config/comandia.php`. Existe para navegar y
     * para reimprimir; el CONTENIDO no se lee de aquí, porque está congelado en `payload`.
     *
     * @return BelongsTo<PosTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PosTicket::class, 'pos_ticket_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PrintJobStatus::Pending->value);
    }
}
