<?php

declare(strict_types=1);

namespace Database\Factories\Costing;

use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use Database\Factories\Catalog\ArticleFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArticleCost>
 *
 * Ojo: crear costos con esta factory **no** actualiza la proyección `article_current_costs`. Es
 * deliberado — la factory escribe historial crudo, y el único camino que mantiene las dos cosas
 * sincronizadas es `CaptureArticleCost`. Las pruebas que necesiten la proyección al día deben usar el
 * servicio; las que prueben la reconstrucción necesitan justamente poder escribir historial sin ella.
 */
final class ArticleCostFactory extends Factory
{
    protected $model = ArticleCost::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => fn (): ArticleFactory => ArticleFactory::new(),
            'unit_cost' => '10.0000',
            'origin' => CostOrigin::Manual,
            'source_cost_id' => null,
            'idempotency_key' => null,
            'notes' => null,
            'actor_membership_id' => null,
            'effective_at' => now(),
        ];
    }

    /**
     * @param  numeric-string  $cost
     */
    public function costing(string $cost): self
    {
        return $this->state(['unit_cost' => $cost]);
    }

    public function origin(CostOrigin $origin): self
    {
        return $this->state(['origin' => $origin]);
    }
}
