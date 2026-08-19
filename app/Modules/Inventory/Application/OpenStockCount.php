<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\StockCountStatus;
use App\Modules\Inventory\Domain\Exceptions\StockCountInvariantException;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockCountLine;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Abre un conteo físico y **congela lo esperado** (D24, §6.2).
 *
 * ## Congelar es lo primero que pasa, no un paso aparte
 *
 * Abrir el conteo equivale a imprimir la hoja: en ese instante queda escrito qué creía el sistema. Por eso no hay
 * estado `draft` —el diseño lo proponía y se quitó— y el alcance se elige en la misma petición: separar «crear» de
 * «congelar» abriría una ventana en la que la hoja que la gente lleva en la mano y lo congelado en la base pueden
 * diferir, que es exactamente el problema que congelar existe para evitar.
 *
 * ## Dos alcances, y la diferencia importa
 *
 *   - **Sin lista de artículos:** conteo general. Se congela una línea por cada fila de saldo del almacén. Un
 *     artículo que el sistema no sabe que existe ahí no entra — y no puede entrar, porque «esperado» no tendría
 *     valor. Se agrega después al capturarlo, que es el caso de encontrar mercancía que nunca se dio de alta.
 *   - **Con lista de artículos:** conteo cíclico («hoy las carnes»). Los artículos de la lista entran **aunque no
 *     tengan saldo**, con esperado en cero: pediste contarlos, y si no hay nada en el estante eso también es un
 *     resultado del conteo.
 *
 * ## El costo se congela aquí, y es el mismo que usará el kardex
 *
 * `unit_cost_at_count` sirve para tres cosas —valuar el reporte, comparar con el umbral de autorización y valuar el
 * ajuste que se escribe al cerrar— y las tres usan **este** valor. Si el cierre releyera el costo vigente, un
 * cambio de costo entre la captura y el cierre haría que se autorizara por una cifra y se registrara otra.
 */
final class OpenStockCount
{
    public function __construct(
        private readonly ContextHolder $context,
        private readonly ResolveArticleCost $costs,
    ) {}

    /**
     * @param  list<Article>  $articles  vacío = conteo general del almacén
     *
     * @throws StockCountInvariantException
     */
    public function open(Warehouse $warehouse, array $articles = [], ?string $notes = null): StockCount
    {
        // Se comprueba antes para poder dar un mensaje útil, y el índice único vuelve a comprobarlo abajo. Lo
        // primero es cortesía; lo segundo es la garantía — entre esta lectura y la escritura cabe otra petición.
        if (StockCount::query()->openIn($warehouse->id)->exists()) {
            throw StockCountInvariantException::alreadyOpenInWarehouse($warehouse->name);
        }

        $membershipId = $this->context->getOrNull()?->membership?->id;

        if ($membershipId === null) {
            // Un conteo sin quien lo empezó no es auditable, y a diferencia de un movimiento de kardex —que un job
            // puede registrar sin actor— un conteo lo hace siempre una persona.
            throw new \LogicException('Un conteo físico exige una membresía en contexto: alguien lo está contando.');
        }

        try {
            return DB::transaction(function () use ($warehouse, $articles, $notes, $membershipId): StockCount {
                $count = StockCount::create([
                    'warehouse_id' => $warehouse->id,
                    'status' => StockCountStatus::Counting,
                    'started_by_membership_id' => $membershipId,
                    'started_at' => CarbonImmutable::now(),
                    'notes' => $notes,
                ]);

                $this->freezeLines($count, $warehouse, $articles);

                return $count->refresh();
            });
        } catch (QueryException $e) {
            // La carrera que la comprobación de arriba no puede cerrar: dos peticiones simultáneas para el mismo
            // almacén. El índice único la rechaza y aquí se traduce al mismo mensaje, porque para quien lo pidió
            // es la misma situación.
            if ($this->isDuplicateOpenCount($e)) {
                throw StockCountInvariantException::alreadyOpenInWarehouse($warehouse->name);
            }

            throw $e;
        }
    }

    /**
     * @param  list<Article>  $articles
     *
     * @throws StockCountInvariantException
     */
    private function freezeLines(StockCount $count, Warehouse $warehouse, array $articles): void
    {
        $requestedIds = array_map(fn (Article $article): int => $article->id, $articles);

        $stocks = ArticleStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->when($requestedIds !== [], fn ($query) => $query->whereIn('article_id', $requestedIds))
            ->get(['article_id', 'lot_id', 'quantity']);

        // Los artículos pedidos que no tienen ni una fila de saldo. Entran con esperado en cero: se pidió contarlos.
        $withoutStock = array_values(array_diff(
            $requestedIds,
            $stocks->pluck('article_id')->unique()->all(),
        ));

        if ($stocks->isEmpty() && $withoutStock === []) {
            throw StockCountInvariantException::nothingToCount($warehouse->name);
        }

        $costs = $this->costs->currentForMany(array_values(array_unique(array_merge(
            $stocks->pluck('article_id')->all(),
            $withoutStock,
        ))));

        $rows = [];

        foreach ($stocks as $stock) {
            $rows[] = [
                'article_id' => $stock->article_id,
                'lot_id' => $stock->lot_id,
                'expected_quantity' => $stock->quantity,
                'unit_cost_at_count' => $costs[$stock->article_id] ?? null,
            ];
        }

        foreach ($withoutStock as $articleId) {
            $rows[] = [
                'article_id' => $articleId,
                'lot_id' => null,
                'expected_quantity' => '0.0000',
                'unit_cost_at_count' => $costs[$articleId] ?? null,
            ];
        }

        foreach ($rows as $row) {
            // Una a una y no con `insert` masivo: el modelo es de dominio y el `tenant_id` lo pone su global
            // scope. Un `insert` en crudo se lo saltaría, que es la clase de atajo que ADR-002 prohíbe.
            StockCountLine::create([
                'stock_count_id' => $count->id,
                ...$row,
            ]);
        }
    }

    private function isDuplicateOpenCount(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'stock_counts_one_open_per_warehouse');
    }
}
