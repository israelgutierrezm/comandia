<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué renglón y cuánto salió en ese papel.
 *
 * ## Por qué lleva cantidad si la línea ya la tiene
 *
 * Porque se comanda de a partes: tres tacos en la comanda de las 20:14 y dos más en la de las 20:31, de la misma línea.
 * Sin la cantidad aquí, cada comanda tendría que decir «5 tacos» y la cocina prepararía ocho.
 *
 * ## Sin ULID a propósito
 *
 * Es el detalle de un papel, no una entidad que la API exponga por sí sola: se lee siempre a través de su ticket. La
 * regla del proyecto es ULID público en lo que la API expone, y esto no lo es.
 */
final class PosTicketItem extends DomainModel
{
    protected $table = 'pos_ticket_items';

    protected $fillable = [
        'pos_ticket_id',
        'pos_order_item_id',
        'quantity',
    ];

    /**
     * @return BelongsTo<PosTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PosTicket::class, 'pos_ticket_id');
    }

    /**
     * @return BelongsTo<PosOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }
}
