<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Domain\Enums\StockMovementDirection;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimiento de inventario — el KARDEX, INMUTABLE (§6.2, §7).
 *
 * La existencia es el acumulado de estas filas; la proyección {@see ArticleStock} existe para no sumarlas en
 * cada lectura, pero **la verdad está aquí**. Nunca se corrige un movimiento: se registra el contrario.
 *
 * Referencia a `Article` y `Warehouse`, que son de otros módulos: `Inventory → Catalog` está declarada, y
 * `Warehouse` es del kernel (`Organization`), del que todo módulo puede depender.
 *
 * @property numeric-string $quantity siempre positiva; la dirección va aparte
 * @property numeric-string|null $unit_cost
 * @property numeric-string|null $total_cost
 * @property numeric-string $balance_after puede ser negativo (§6.2)
 * @property StockMovementKind $kind
 * @property StockMovementDirection $direction
 */
final class StockMovement extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'stock_movements';

    protected $fillable = [
        'warehouse_id',
        'article_id',
        'lot_id',
        'waste_reason_id',
        'kind',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'source_type',
        'source_id',
        'source_ulid',
        'idempotency_key',
        'actor_membership_id',
        'notes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => StockMovementKind::class,
            'direction' => StockMovementDirection::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',

            // Las cantidades y los costos SIN cast a float: entran en aritmética `bcmath` (§7, P3). Un
            // `float` aquí desharía la razón por la que las columnas tienen cuatro decimales.
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<ArticleLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ArticleLot::class, 'lot_id');
    }

    /**
     * El motivo, sólo en las mermas (D27).
     *
     * La merma no es una tabla propia: es un movimiento con motivo. Un documento aparte duplicaría cantidad y
     * costo, y esa duplicación es de donde salen los descuadres entre el reporte de mermas y el kardex.
     *
     * @return BelongsTo<WasteReason, $this>
     */
    public function wasteReason(): BelongsTo
    {
        return $this->belongsTo(WasteReason::class, 'waste_reason_id');
    }

    /**
     * Quién lo registró. `null` = lo movió un job y no una persona.
     *
     * @return BelongsTo<TenantMembership, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    /**
     * El kardex de un artículo en un almacén, del más reciente al más antiguo.
     *
     * Va aquí y no repetido en cada consulta porque es LA lectura de la tabla, y el orden importa: un kardex
     * se lee del último movimiento hacia atrás, como un estado de cuenta.
     *
     * `id` como segundo criterio: dos movimientos pueden compartir `occurred_at` —el mismo segundo, o la
     * misma fecha capturada a mano— y sin desempate el orden sería el que MySQL quisiera, así que el saldo
     * de la columna derecha parecería ir hacia atrás.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeKardex(Builder $query, int $warehouseId, int $articleId): Builder
    {
        return $query
            ->where('warehouse_id', $warehouseId)
            ->where('article_id', $articleId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /** El importe con signo aplicado, para reportes que suman entradas y salidas juntas. */
    public function signedQuantity(): string
    {
        return $this->direction === StockMovementDirection::In
            ? $this->quantity
            : '-'.$this->quantity;
    }
}
