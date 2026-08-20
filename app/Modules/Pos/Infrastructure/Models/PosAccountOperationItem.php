<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un item que cambió de cuenta, con su origen y su destino.
 *
 * Guarda las dos cuentas POR ITEM y no sólo en la cabecera, porque una operación puede tener varias procedencias:
 * juntar tres cuentas en una es un solo hecho con tres orígenes.
 *
 * Sin ULID: es el detalle de una operación y se lee siempre a través de ella.
 */
final class PosAccountOperationItem extends DomainModel
{
    use Immutable;

    protected $table = 'pos_account_operation_items';

    protected $fillable = [
        'operation_id',
        'pos_order_item_id',
        'from_account_id',
        'to_account_id',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<PosAccountOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(PosAccountOperation::class, 'operation_id');
    }

    /**
     * @return BelongsTo<PosOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'to_account_id');
    }
}
