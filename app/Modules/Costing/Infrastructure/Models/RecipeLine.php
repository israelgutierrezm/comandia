<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un componente de una receta con su rendimiento (D21).
 *
 * **No lleva ULID**: no se expone como recurso propio de la API. La receta se guarda y se lee completa
 * —`PUT /articles/{ulid}/recipe`— porque es una unidad de sentido: validar ciclos y rendimientos sobre
 * un estado intermedio de líneas sueltas sería validar algo que no existe.
 *
 * `yield_percent` **divide**: 200 g de cebolla utilizable al 80 % de rendimiento son 250 g comprados.
 *
 * @property numeric-string $quantity
 * @property numeric-string $yield_percent
 */
final class RecipeLine extends DomainModel
{
    protected $table = 'recipe_lines';

    protected $fillable = [
        'recipe_id',
        'component_article_id',
        'quantity',
        'unit_id',
        'yield_percent',
        'sort_order',
    ];

    protected $attributes = [
        'yield_percent' => '100.00',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',

            // `quantity` y `yield_percent` sin cast a float: son los dos factores del costo de la
            // línea, y el segundo además divide (§7, P3).
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'component_article_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * El factor por el que hay que multiplicar por el rendimiento.
     *
     * Se expone aquí y no se calcula en el motor de costeo para que la dirección de la operación viva en
     * **un solo sitio**: invertirla subvalúa sistemáticamente todos los costos del catálogo, siempre en
     * el mismo sentido, y el margen reportado sale optimista sin que nada falle.
     *
     * @return numeric-string
     */
    public function yieldDivisor(): string
    {
        return bcdiv($this->yield_percent, '100', 8);
    }
}
