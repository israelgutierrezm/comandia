<?php

declare(strict_types=1);

namespace App\Modules\Costing\Infrastructure\Models;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una variación de costo — INMUTABLE (D14, §7).
 *
 * El costo vigente es la última fila por `effective_at`. La proyección
 * {@see ArticleCurrentCost} existe para no consultarla N veces al costear una receta, pero **la
 * verdad está aquí**.
 *
 * Referencia a `Article`, que es de `Catalog`: es la dependencia declarada `Costing → Catalog` (P1),
 * y va en este sentido y no en el otro.
 *
 * @property numeric-string $unit_cost
 * @property CostOrigin $origin
 * @property string|null $idempotency_key
 */
final class ArticleCost extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'article_costs';

    protected $fillable = [
        'article_id',
        'unit_cost',
        'origin',
        'source_cost_id',
        'idempotency_key',
        'notes',
        'actor_membership_id',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'origin' => CostOrigin::class,
            'effective_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',

            // `unit_cost` sin cast a float: entra en aritmética `bcmath` (§7, P3).
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
     * La variación que disparó ésta: "la torta subió porque subió el jitomate".
     *
     * @return BelongsTo<self, $this>
     */
    public function sourceCost(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_cost_id');
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    /**
     * Sólo costos de adquisición (D14).
     *
     * Es el scope del promedio del periodo: mezclarlo con costos calculados daría un número sin
     * significado.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAcquisitions(Builder $query): Builder
    {
        return $query->whereIn('origin', CostOrigin::acquisitionValues());
    }

    /**
     * El costo vigente de un artículo según el historial, sin pasar por la proyección.
     *
     * Es la definición de "costo vigente" de D14 hecha consulta, y la usa el comando de
     * reconstrucción: la proyección no puede ser su propia fuente de verdad.
     */
    public static function currentFor(int $articleId): ?self
    {
        return self::query()
            ->where('article_id', $articleId)
            // Desempate por `id`: dos capturas con el mismo `effective_at` —el mismo día, dos
            // facturas— dejarían el orden indefinido, y "el costo vigente" tiene que ser
            // determinista o la proyección y el historial divergen sin motivo aparente.
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }
}
