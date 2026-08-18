<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Http\Requests\StoreArticleLotRequest;
use App\Modules\Inventory\Http\Requests\UpdateArticleLotRequest;
use App\Modules\Inventory\Http\Resources\ArticleLotResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lotes de un artículo (D23).
 *
 * ## Por qué estos endpoints existen si FEFO es automático
 *
 * `inventory.lots.manage` está en el catálogo cerrado desde la Iteración 1 y no tenía ruta. La revisión de cierre
 * de la Iteración 2 encontró justo eso con otro permiso (D140): uno que un tenant puede conceder y que no hace
 * nada. Repetirlo aquí a sabiendas sería peor que la primera vez.
 *
 * Y hacen falta de verdad, aunque la salida elija sola: **corregir una caducidad mal teclada** y **marcar un lote
 * como caducado** son cosas que sólo una persona puede decidir. El sistema no da por caducada la mercancía por su
 * cuenta — hasta que alguien registra la merma, el saldo sigue ahí.
 *
 * ## El listado se ordena por FEFO
 *
 * El mismo orden con el que va a salir: primero lo que caduca, y los que no caducan al final. Así la pantalla
 * dice, sin que nadie lo explique, de dónde va a salir lo siguiente.
 */
final class ArticleLotController
{
    /**
     * @return AnonymousResourceCollection<Collection<int, ArticleLot>>
     */
    public function index(Request $request, Article $article): AnonymousResourceCollection
    {
        // Sin paginar: los lotes VIVOS de un artículo son pocos —los agotados se pueden filtrar aparte— y
        // paginarlos obligaría a recorrer páginas para saber de dónde va a salir lo siguiente.
        $lots = ArticleLot::query()
            ->fefo($article->id)
            ->when(
                $request->filled('status'),
                fn ($query) => $query->reorder()
                    ->where('status', $request->string('status')->toString())
                    ->orderBy('expires_at')
            )
            ->get();

        return ArticleLotResource::collection($lots);
    }

    public function store(StoreArticleLotRequest $request, Article $article): JsonResponse
    {
        $lot = ArticleLot::create([
            'article_id' => $article->id,
            ...$request->safe()->only(['code', 'expires_at', 'received_at']),
        ]);

        return (new ArticleLotResource($lot->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateArticleLotRequest $request, ArticleLot $lot): ArticleLotResource
    {
        $lot->update($request->safe()->only(['expires_at', 'status']));

        return new ArticleLotResource($lot->refresh());
    }

    /**
     * Marca el lote como CADUCADO.
     *
     * Acción propia y no un `PATCH` de estado, por lo mismo que suspender a una persona no es editarla: es una
     * decisión con consecuencia —el lote deja de surtir— y merece su propia entrada en el registro de lo que
     * alguien hizo.
     *
     * **No registra la merma.** El saldo sigue ahí hasta que alguien la registre con su motivo (D27), y eso es
     * deliberado: dar por perdida la mercancía automáticamente convertiría un vencimiento de calendario en una
     * pérdida contable que nadie revisó. Muchas veces el lote se revisa y parte se salva.
     */
    public function expire(ArticleLot $lot): ArticleLotResource
    {
        $lot->update(['status' => LotStatus::Expired]);

        return new ArticleLotResource($lot->refresh());
    }
}
