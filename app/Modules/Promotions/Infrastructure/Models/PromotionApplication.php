<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El registro por venta de una promoción aplicada. INMUTABLE (D312).
 *
 * Se escribe una vez, al cobrar, desde el oyente del evento del kernel; nunca se reescribe. Referencia los documentos
 * del POS por ULID, sin FK, porque este módulo no depende de `Pos`.
 */
final class PromotionApplication extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'promotion_applications';

    protected $fillable = [
        'promotion_id',
        'pos_account_ulid',
        'pos_order_item_ulid',
        'pos_discount_ulid',
        'amount_discounted',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_discounted' => 'string',
            'applied_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
