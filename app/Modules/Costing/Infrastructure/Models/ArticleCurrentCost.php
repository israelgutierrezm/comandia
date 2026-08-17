<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El costo vigente de un artículo — PROYECCIÓN, no verdad (P4).
 *
 * La verdad es la última fila de {@see ArticleCost}. Esto es una caché con forma de tabla, y existe
 * porque costear una receta de 30 líneas exigiría 30 consultas de "última fila por artículo", y una
 * receta con sub-recetas las multiplica por nivel.
 *
 * Mismo patrón que la especificación usa en inventarios: "kardex como fuente de verdad; existencia
 * como acumulado" (§6.2).
 *
 * **No lleva ULID:** no se expone como recurso propio de la API. Viaja dentro del artículo, que es
 * el recurso que el cliente conoce. Un identificador público para una caché sería invitar a alguien
 * a tratarla como entidad.
 *
 * @property numeric-string $unit_cost
 */
final class ArticleCurrentCost extends DomainModel
{
    protected $table = 'article_current_costs';

    protected $fillable = [
        'article_id',
        'unit_cost',
        'effective_at',
        'source_cost_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<ArticleCost, $this>
     */
    public function sourceCost(): BelongsTo
    {
        return $this->belongsTo(ArticleCost::class, 'source_cost_id');
    }
}
