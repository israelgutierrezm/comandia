<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un modificador aplicado a una línea, con su nombre y su precio CONGELADOS.
 *
 * Por lo mismo que en la línea: el ticket reimpreso un mes después tiene que decir lo que decía el original, aunque el
 * modificador se haya renombrado o subido de precio.
 */
final class PosOrderItemModifier extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_order_item_modifiers';

    protected $fillable = ['pos_order_item_id', 'modifier_id', 'modifier_name', 'extra_price', 'quantity'];

    protected $attributes = ['quantity' => 1];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<PosOrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PosOrderItem::class, 'pos_order_item_id');
    }

    /**
     * @return BelongsTo<Modifier, $this>
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    /**
     * Lo que este modificador suma a la línea: su precio por su cantidad.
     *
     * Se calcula aquí y con `bcmul` porque son los 3 shots de D7 — tres veces el precio del shot— y multiplicar dinero
     * con floats es exactamente lo que §7 prohíbe.
     */
    public function total(): string
    {
        return bcmul((string) $this->extra_price, (string) $this->quantity, 2);
    }
}
