<?php

declare(strict_types=1);

namespace App\Modules\Costing\Jobs;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Costing\Application\RecipeCycleDetector;
use App\Modules\Costing\Application\RecostArticle;
use App\Modules\Costing\Domain\Exceptions\CostCycleDetectedException;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Support\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula el costo de todo lo que depende de un artículo (D16).
 *
 * Cambiar el costo de la harina tiene que llegar al pan, y de ahí a la torta. Este job recorre el grafo de
 * recetas en la dirección inversa —el índice `(tenant_id, component_article_id)` de `recipe_lines`— y
 * recostea cada dependiente.
 *
 * ## Cola `default`, no `critical`
 *
 * Un costo no es verdad contable y el POS no se detiene por él (§6.2: "el POS nunca se bloquea"). El
 * catálogo de colas ya nombra este caso: la cola `default` es para "recálculo de precios sugeridos en
 * cascada".
 *
 * ## Lleva IDENTIFICADORES, no modelos
 *
 * `SerializesModels` volvería a consultar el modelo al deserializar, y en el worker **no hay contexto de
 * tenant**: el global scope lanzaría `MissingTenantContextException` antes de que el job pudiera abrir el
 * contexto. Es una trampa con forma de comodidad, y la única salida es no depender de ella.
 *
 * ## El tenant viaja en el job, y es la segunda fuente legítima
 *
 * ADR-002 dice que el `tenant_id` jamás llega como parámetro del cliente. Un job no es un cliente: es la
 * continuación de una petición que ya lo resolvió. El job lo lleva explícito y abre el contexto con
 * `runFor()`, que es la única manera de que el scope global funcione fuera de una petición.
 *
 * ## Idempotencia
 *
 * Cada recosteo usa una llave determinista `cascade:{costo origen}:{artículo}`, y el índice único de
 * `article_costs` la hace infalsificable. Re-despachar el job **no duplica historial** (CLAUDE.md).
 *
 * NO implementa `ShouldBeUnique`, y es deliberado: dos capturas de costo distintas del mismo insumo llevan
 * `sourceCostId` distinto, así que no colisionarían de todos modos, y un reintento de la cola se salta la
 * unicidad por diseño. La garantía que importa —no duplicar historial— la da el índice único, y añadir una
 * segunda que no aporta nada sólo introduciría una dependencia del lock de cache.
 *
 * ## Por qué el orden de los dependientes no importa
 *
 * Podría parecer que hay que recostear de abajo hacia arriba —la masa antes que el pan, el pan antes que la
 * torta— pero no: el motor **recalcula las sub-recetas** en lugar de leer su proyección (D107), así que cada
 * recosteo baja hasta las hojas por su cuenta. El orden sólo cambiaría en qué instante se escribe cada fila
 * de historial, no el número.
 */
final class RecalculateDependentCosts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Reintentos: tres. El fallo plausible aquí es un bloqueo de base o un despliegue a media corrida, no un
     * error de datos —los datos ya los validó quien guardó la receta—.
     */
    public int $tries = 3;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $articleId,
        private readonly ?int $sourceCostId = null,
    ) {
        $this->onQueue(Queue::Default->value);
    }

    public function handle(TenantContext $context, RecipeCycleDetector $detector, RecostArticle $recost): void
    {
        $context->runFor($this->tenantId, function () use ($detector, $recost): void {
            $dependents = $detector->loadGraph()->dependentsOf($this->articleId);

            if ($dependents === []) {
                return;
            }

            $sourceCost = $this->sourceCostId === null
                ? null
                : ArticleCost::query()->find($this->sourceCostId);

            // Una consulta para todos: recostear 40 dependientes no puede costar 40 consultas sólo para
            // resolver los artículos.
            $articles = Article::query()
                ->whereIn('id', $dependents)
                ->with('baseUnit')
                ->get();

            foreach ($articles as $article) {
                try {
                    $recost->recost(
                        article: $article,
                        sourceCost: $sourceCost,
                        idempotencyKey: sprintf(
                            'cascade:%s:%d',
                            $this->sourceCostId ?? 'recipe',
                            $article->id,
                        ),
                    );
                } catch (CostCycleDetectedException $e) {
                    // Dato corrupto: guardar un ciclo es imposible por la vía normal. Se registra y se sigue
                    // con los demás dependientes en lugar de tirar el job entero — un artículo con recetas
                    // rotas no debe impedir que los otros treinta queden costeados.
                    Log::warning('Costeo en cascada omitido por ciclo en recetas', [
                        'tenant_id' => $this->tenantId,
                        'article_id' => $article->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
