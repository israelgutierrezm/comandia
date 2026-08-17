<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Http\Requests\StoreArticleCostRequest;
use App\Modules\Costing\Http\Resources\ArticleCostResource;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Costos de un artículo: el vigente, el historial y el promedio del periodo (D14).
 *
 * Vive en `Costing` y no en `Catalog` porque el costo es de `Costing` (P1). La consecuencia visible es
 * que una pantalla de catálogo con columna de costo hace dos llamadas; la alternativa era que
 * `Catalog` conociera a `Costing`, y el grafo tendría un ciclo el día que `Costing` necesite escribir
 * un precio sugerido — que es el día siguiente.
 */
final class ArticleCostController
{
    public function __construct(private readonly CaptureArticleCost $capture) {}

    /**
     * El costo vigente del artículo, más el promedio del periodo como referencia visual.
     *
     * D14 es explícito: el promedio "se muestra como referencia visual, **no se usa para cálculo**".
     * Por eso se calcula al leer y no se almacena: almacenarlo crearía la segunda fuente que la propia
     * decisión prohíbe, y alguien acabaría costeando con ella.
     */
    public function show(Request $request, Article $article): JsonResponse
    {
        $projection = ArticleCurrentCost::query()
            ->where('article_id', $article->id)
            ->first();

        $days = max(1, min((int) $request->integer('average_days', 30), 365));
        $since = CarbonImmutable::now()->subDays($days);

        // Sólo adquisiciones: promediar el costo calculado de un platillo con el costo de compra de
        // un insumo mezcla dos magnitudes distintas.
        $average = ArticleCost::query()
            ->acquisitions()
            ->where('article_id', $article->id)
            ->where('effective_at', '>=', $since)
            ->avg('unit_cost');

        return new JsonResponse([
            'data' => [
                'article_ulid' => $article->ulid,

                // NULL significa "todavía no tiene costo", no cero. Un cero diría que es gratis, y
                // la diferencia importa: un artículo sin costo no se puede costear ni sugerirle
                // precio, y la UI tiene que decirlo en lugar de mostrar un margen del 100 %.
                'unit_cost' => $projection?->unit_cost,
                'effective_at' => $projection?->effective_at?->toIso8601String(),

                'period_average' => $average === null ? null : (string) round((float) $average, 4),
                'period_days' => $days,

                // Referencia visual, no base de cálculo (D14). Viaja explícito para que ningún
                // cliente lo use por error creyendo que es el costo vigente.
                'period_average_is_reference_only' => true,
            ],
        ]);
    }

    /**
     * Historial de costos, del más reciente al más antiguo.
     *
     * Paginación por **cursor** y no por página: es una tabla que crece con cada compra de cada
     * insumo, y en ella no hay "página 500" a la que saltar — se investiga hacia atrás (§8).
     */
    public function index(Request $request, Article $article): JsonResponse
    {
        $query = new ListQuery(
            filters: ['origin' => 'origin'],
            sortable: ['effective_at'],
            defaultSort: 'effective_at',
            dateRanges: ['effective' => 'effective_at'],
        );

        $costs = $query
            ->apply(
                ArticleCost::query()
                    ->where('article_id', $article->id)
                    ->with(['actor', 'sourceCost']),
                $request,
            )
            // El orden lo fija el endpoint y no el cliente: un historial se lee del presente al
            // pasado. `ListQuery` ya aplicó su orden por defecto; éste lo reemplaza a propósito.
            ->reorder()
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->cursorPaginate($query->perPage($request));

        return ArticleCostResource::collection($costs)->response();
    }

    /**
     * Captura manual de costo, por unidad o por presentación de compra.
     */
    public function store(
        StoreArticleCostRequest $request,
        Article $article,
        ContextHolder $context,
    ): JsonResponse {
        $effectiveAt = $request->filled('effective_at')
            ? CarbonImmutable::parse($request->string('effective_at')->toString())
            : null;

        $actorId = $context->getOrNull()?->membership?->id;
        $notes = $request->filled('notes') ? $request->string('notes')->toString() : null;

        if ($request->filled('presentation_ulid')) {
            $presentation = ArticlePurchasePresentation::findByUlid(
                $request->string('presentation_ulid')->toString()
            );

            // El Form Request ya verificó que existe, que es de este artículo y que está activa.
            // Este `assert` documenta la precondición sin repetir la validación: dos verificaciones
            // son dos sitios donde una puede quedarse desactualizada.
            assert($presentation !== null);

            $cost = $this->capture->fromPresentation(
                presentation: $presentation,
                totalCost: $request->string('total_cost')->toString(),
                origin: CostOrigin::Manual,
                effectiveAt: $effectiveAt,
                notes: $notes,
                actorMembershipId: $actorId,
            );
        } else {
            $cost = $this->capture->atUnitCost(
                article: $article,
                unitCost: $request->string('unit_cost')->toString(),
                origin: CostOrigin::Manual,
                effectiveAt: $effectiveAt,
                notes: $notes,
                actorMembershipId: $actorId,
            );
        }

        return (new ArticleCostResource($cost->load(['actor'])))
            ->response()
            ->setStatusCode(201);
    }
}
