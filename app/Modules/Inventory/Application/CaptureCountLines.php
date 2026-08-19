<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Exceptions\StockCountInvariantException;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockCountLine;
use Illuminate\Support\Facades\DB;

/**
 * Captura las cantidades contadas: un `PUT` masivo sobre las líneas de un conteo.
 *
 * ## Masivo porque así llega la hoja de papel
 *
 * Una lista de renglones, no cien peticiones. Y es idempotente por construcción: reenviar la misma hoja deja el
 * mismo resultado, que es lo que hace segura la captura desde una tableta con conexión intermitente en un almacén.
 *
 * ## Capturar un artículo que no estaba en la hoja crea su línea
 *
 * Es el caso de encontrar mercancía que el sistema no sabía que existía ahí, y es información valiosa: casi siempre
 * significa que una recepción se registró en otro almacén. Su `expected_quantity` se congela **en ese momento** —no
 * puede ser de antes, porque antes nadie sabía que hacía falta la línea— y el costo también.
 *
 * ## Contar cero es distinto de no contar
 *
 * `counted_quantity` en `NULL` significa «no se contó» y no genera ajuste; un cero explícito significa «se contó y
 * no había» y genera el ajuste que vacía la existencia. Es la razón por la que la petición admite `null` como valor
 * de una línea: sirve para **deshacer** una captura equivocada antes de cerrar.
 */
final class CaptureCountLines
{
    public function __construct(private readonly ResolveArticleCost $costs) {}

    /**
     * @param  list<array{article: Article, lot: ArticleLot|null, counted_quantity: numeric-string|null}>  $entries
     * @return int cuántas líneas quedaron escritas
     *
     * @throws StockCountInvariantException
     */
    public function capture(StockCount $count, array $entries): int
    {
        return DB::transaction(function () use ($count, $entries): int {
            // Con la fila bloqueada: entre leer el estado y escribir las líneas cabe un cierre, y las líneas
            // escritas después del cierre serían capturas que nunca se aplicaron y que nadie vería.
            $locked = StockCount::query()->lockForUpdate()->whereKey($count->id)->sole();

            if (! $locked->isOpen()) {
                throw StockCountInvariantException::notOpen();
            }

            foreach ($entries as $entry) {
                $this->captureOne($locked, $entry['article'], $entry['lot'], $entry['counted_quantity']);
            }

            return count($entries);
        });
    }

    /**
     * @param  numeric-string|null  $countedQuantity
     */
    private function captureOne(
        StockCount $count,
        Article $article,
        ?ArticleLot $lot,
        ?string $countedQuantity,
    ): void {
        $line = StockCountLine::query()
            ->where('stock_count_id', $count->id)
            ->where('article_id', $article->id)
            ->when($lot === null, fn ($query) => $query->whereNull('lot_id'))
            ->when($lot !== null, fn ($query) => $query->where('lot_id', $lot?->id))
            ->first();

        if ($line !== null) {
            $line->update(['counted_quantity' => $countedQuantity]);

            return;
        }

        // Línea nueva: el artículo no estaba en la hoja. Lo esperado se congela ahora, con lo que el sistema cree
        // en este instante — que para un artículo sin fila de saldo es cero.
        $expected = ArticleStock::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->where('article_id', $article->id)
            ->when($lot === null, fn ($query) => $query->whereNull('lot_id'))
            ->when($lot !== null, fn ($query) => $query->where('lot_id', $lot?->id))
            ->value('quantity');

        StockCountLine::create([
            'stock_count_id' => $count->id,
            'article_id' => $article->id,
            'lot_id' => $lot?->id,
            'expected_quantity' => is_string($expected) ? $expected : '0.0000',
            'counted_quantity' => $countedQuantity,
            'unit_cost_at_count' => $this->costs->current($article),
        ]);
    }
}
