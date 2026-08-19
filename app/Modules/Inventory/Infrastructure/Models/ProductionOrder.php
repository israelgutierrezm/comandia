<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\ProductionOrderStatus;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una orden de producción (D17, P8).
 *
 * Una orden **completada es inmutable**, por lo mismo que un conteo cerrado (D175): sus movimientos ya están en el
 * kardex, que no admite corrección. Rehacer una producción mal registrada es cancelarla —lo que exige una orden
 * inversa— no editarla.
 *
 * @property int $id
 * @property ProductionOrderStatus $status
 */
final class ProductionOrder extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'production_orders';

    protected $fillable = [
        'warehouse_id',
        'article_id',
        'recipe_id',
        'status',
        'planned_quantity',
        'produced_quantity',
        'unit_cost_at_production',
        'created_by_membership_id',
        'produced_by_membership_id',
        'produced_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductionOrderStatus::class,
            'produced_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Se comprueba el valor ORIGINAL en crudo: con el cast de enum, `getOriginal` devuelve el enum construido y
        // compararlo con una cadena nunca sería igual — la guarda rechazaría hasta la propia transición a completada
        // (la lección que costó una corrida en el paso 5).
        self::updating(function (self $order): void {
            $original = $order->getRawOriginal('status');

            if ($original !== null && $original !== ProductionOrderStatus::Draft->value) {
                throw new \RuntimeException(
                    'Una orden de producción completada o cancelada no se puede modificar. Sus movimientos ya están '
                    .'en el kardex: para corregirla, produce en sentido inverso con otra orden.'
                );
            }
        });
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Article, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return HasMany<ProductionOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class, 'production_order_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by_membership_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function producedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'produced_by_membership_id');
    }

    /**
     * El movimiento de ENTRADA del producible. Es uno solo, y es lo que distingue esta relación de `lines()`:
     * las líneas son los consumos y esto es lo que se produjo.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function outputMovement(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'source_id')
            ->where('source_type', self::class)
            ->where('kind', 'production_in');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlanned(Builder $query): Builder
    {
        return $query->where('status', ProductionOrderStatus::Draft->value);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
