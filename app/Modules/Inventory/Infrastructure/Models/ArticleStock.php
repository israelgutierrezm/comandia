<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El saldo de un artículo en un almacén — PROYECCIÓN, no verdad.
 *
 * La verdad está en {@see StockMovement}. Esta tabla existe porque «¿cuánto tengo?» se pregunta mil veces al
 * día y contestarla sumando el kardex sería sumar la tabla más grande del sistema en cada lectura.
 *
 * ## Sin ULID público, a propósito
 *
 * Un saldo no es una entidad que se exponga por sí misma: se lee siempre a través del artículo o del almacén,
 * que sí tienen identificador público. Darle uno invitaría a construir URLs sobre él, y entonces cambiar la
 * forma de la proyección rompería clientes.
 *
 * @property numeric-string $quantity puede ser NEGATIVA (§6.2)
 */
final class ArticleStock extends DomainModel
{
    protected $table = 'article_stocks';

    protected $fillable = [
        'warehouse_id',
        'article_id',
        'lot_id',
        'quantity',
        'last_movement_id',
    ];

    protected function casts(): array
    {
        return [
            // `quantity` SIN cast a float: entra en aritmética `bcmath`. Es la razón por la que la columna
            // tiene cuatro decimales.
            'lot_key' => 'int',
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
     * El movimiento que dejó este saldo.
     *
     * Es el testigo que hace **auditable** la proyección: si `quantity` no coincide con el `balance_after` de
     * este movimiento, la proyección se desvió, y detectarlo no exige recorrer el kardex.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'last_movement_id');
    }

    /**
     * Los saldos de un almacén que están en negativo.
     *
     * No es una anomalía que haya que arreglar: §6.2 permite existencias negativas porque el POS nunca se
     * bloquea. Es la lista de «lo que el conteo tiene que revisar», y por eso vale una consulta propia.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNegative(Builder $query): Builder
    {
        return $query->where('quantity', '<', 0);
    }

    public function isNegative(): bool
    {
        return bccomp($this->quantity, '0', 4) === -1;
    }
}
