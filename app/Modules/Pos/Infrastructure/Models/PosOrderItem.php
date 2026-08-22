<?php

declare(strict_types=1);

namespace App\Modules\Pos\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La línea que se cobra, con el precio CONGELADO al capturarla.
 *
 * @property PosOrderItemStatus $status
 */
final class PosOrderItem extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'pos_order_items';

    protected $fillable = [
        'pos_order_id',
        'pos_account_id',
        'article_id',
        'preparation_area_id',
        'status',
        'quantity',
        'article_name',
        'unit_price',
        'unit_cost',
        'vat_rate',
        'modifiers_total',
        'discount_amount',
        'is_courtesy',
        'cancelled_reason',
        'cancelled_by_membership_id',
        'cancelled_at',
        'cancellation_destination',
        'captured_by_membership_id',
    ];

    protected $attributes = [
        'status' => 'captured',
        'modifiers_total' => 0,
        'discount_amount' => 0,
        'is_courtesy' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => PosOrderItemStatus::class,
            'is_courtesy' => 'boolean',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PosOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    /**
     * @return BelongsTo<PosAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PosAccount::class, 'pos_account_id');
    }

    /**
     * El artículo del catálogo.
     *
     * Existe para el consumo de inventario y para navegar, NO para leer el precio: ése está congelado en la línea. Si
     * alguien lo usara para calcular el total, todo el congelado dejaría de servir de nada.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<PreparationArea, $this>
     */
    public function preparationArea(): BelongsTo
    {
        return $this->belongsTo(PreparationArea::class);
    }

    /**
     * @return HasMany<PosOrderItemModifier, $this>
     */
    public function modifiers(): HasMany
    {
        return $this->hasMany(PosOrderItemModifier::class, 'pos_order_item_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'captured_by_membership_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'cancelled_by_membership_id');
    }

    public function wasCommanded(): bool
    {
        return $this->status->wasCommanded();
    }

    public function isBillable(): bool
    {
        return $this->status->isBillable();
    }

    /**
     * El IVA contenido en esta línea.
     *
     * ## Los precios son IVA INCLUIDO (D30), así que el impuesto se EXTRAE, no se suma
     *
     * `total − (total ÷ (1 + tasa/100))`. Es la operación que más se hace mal en un POS: sumar la tasa al total daría un
     * impuesto mayor del real y un desglose que no cuadra con lo que el cliente pagó.
     *
     * Se calcula aquí y con `bcmath` porque es dinero, y con la tasa CONGELADA en la línea — no la del negocio hoy. Un
     * ticket reimpreso después de un cambio de tasa tiene que seguir desglosando lo que se cobró.
     */
    public function vatAmount(): string
    {
        $total = (string) $this->line_total;

        if (bccomp((string) $this->vat_rate, '0', 2) === 0) {
            return '0.00';
        }

        $divisor = bcadd('1', bcdiv((string) $this->vat_rate, '100', 6), 6);
        $base = bcdiv($total, $divisor, 6);

        // `Decimal::round()` y NO `bcsub(..., 2)`: bcmath TRUNCA en lugar de redondear.
        //
        // 45.00 al 16 % contiene 6.2069 de impuesto, y truncar daba 6.20 — un centavo de menos en cada renglón, que en
        // un día de servicio son varios pesos que el desglose no explica. Lo dijo una prueba que esperaba 6.21, y el
        // error es justo el que §7 quiere evitar al exigir bcmath: usarlo no basta si se trunca donde toca redondear.
        return Decimal::round(bcsub($total, $base, 6), 2);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('status', '!=', PosOrderItemStatus::Cancelled->value);
    }
}
