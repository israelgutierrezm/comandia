<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Purchasing\Application\CompareSupplierPrices;
use App\Modules\Purchasing\Application\RecordSupplierPrice;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Purchasing\Http\Requests\StoreSupplierPriceRequest;
use App\Modules\Purchasing\Http\Resources\SupplierPriceResource;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Http\Query\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Historial de precios de proveedor y su comparación (D26, §6.2).
 *
 * ## Tres endpoints porque son tres preguntas
 *
 *   - **El historial de un proveedor**: «¿qué me ha cobrado éste?» — es la ficha de la negociación.
 *   - **La comparación por artículo**: «¿quién me lo vende más barato y quién me subió?» — es la razón de ser de la
 *     tabla, y la única que hace trabajo de verdad.
 *   - **La captura**: una cotización o un precio de lista. El grueso del historial llega solo desde las recepciones
 *     (paso 9); esto es para lo que se sabe antes de comprar.
 *
 * No hay endpoint de edición ni de borrado: el historial es **inmutable** (§7). Se corrige agregando otra observación,
 * porque si el precio se capturó mal, lo cierto es que hubo un error de captura ese día — y borrarlo hace que el
 * historial mienta sobre lo que se sabía entonces.
 */
final class SupplierPriceController
{
    public function __construct(
        private readonly RecordSupplierPrice $prices,
        private readonly CompareSupplierPrices $comparison,
    ) {}

    /**
     * El historial de un proveedor.
     *
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, SupplierPrice>>
     */
    public function forSupplier(Request $request, Supplier $supplier): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['source' => 'source', 'currency' => 'currency'],
            sortable: ['observed_at', 'unit_price'],
            searchable: [],
            // Lo más reciente primero: un historial se lee del presente al pasado, y `ListQuery` desempata por llave
            // para que varias observaciones del mismo día tengan un orden estable (D182).
            defaultSort: '-observed_at',
            dateRanges: ['observed' => 'observed_at'],
            handledByCaller: ['article'],
        );

        $prices = $query->apply(
            SupplierPrice::query()
                ->where('supplier_id', $supplier->id)
                ->with(['article.baseUnit', 'presentation', 'registeredBy.user']),
            $request,
        );

        if ($request->filled('article')) {
            $prices->whereHas('article', fn ($q) => $q->where('ulid', $request->string('article')));
        }

        return SupplierPriceResource::collection($prices->paginate($query->perPage($request)));
    }

    /**
     * La comparación entre proveedores de un artículo, con la variación de cada uno.
     *
     * Sin paginar: un artículo tiene un puñado de proveedores, y paginar una comparación obligaría a recorrer páginas
     * para encontrar el más barato — que es justo lo que esta vista contesta.
     */
    public function forArticle(Article $article): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'article' => [
                    'ulid' => $article->ulid,
                    'name' => $article->name,
                    'base_unit_code' => $article->baseUnit?->code,
                ],

                // Agrupado por moneda dentro de la lista: no hay tipo de cambio en el sistema, así que dos monedas no
                // se comparan en lugar de compararse mal.
                'suppliers' => $this->comparison->forArticle($article),
            ],
        ]);
    }

    public function store(StoreSupplierPriceRequest $request, Supplier $supplier): JsonResponse
    {
        $article = Article::query()->where('ulid', $request->string('article_ulid'))->sole();

        $source = SupplierPriceSource::from(
            $request->string('source', SupplierPriceSource::Manual->value)->toString()
        );

        $observedAt = $request->filled('observed_at')
            ? CarbonImmutable::parse($request->string('observed_at')->toString())
            : null;

        $currency = mb_strtoupper($request->string('currency', 'MXN')->toString());

        $price = $request->filled('presentation_ulid')
            ? $this->prices->forPresentation(
                supplier: $supplier,
                presentation: ArticlePurchasePresentation::query()
                    ->where('ulid', $request->string('presentation_ulid'))
                    ->sole(),
                pricePerPresentation: $request->string('price')->toString(),
                source: $source,
                observedAt: $observedAt,
                currency: $currency,
                notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            )
            : $this->prices->forBaseUnit(
                supplier: $supplier,
                article: $article,
                unitPrice: $request->string('price')->toString(),
                source: $source,
                observedAt: $observedAt,
                currency: $currency,
                notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
            );

        return (new SupplierPriceResource(
            $price->load(['supplier', 'article.baseUnit', 'presentation', 'registeredBy.user'])
        ))->response()->setStatusCode(201);
    }

    /**
     * El catálogo de orígenes, para que el cliente arme su filtro sin escribir las etiquetas a mano (D139).
     */
    public function sources(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (SupplierPriceSource $source): array => [
                'value' => $source->value,
                'label' => $source->label(),
                // Cuáles puede capturar una persona: `receipt` lo escribe el sistema al confirmar una recepción, y sin
                // decirlo aquí el cliente lo ofrecería y recibiría un 422.
                'capturable_by_hand' => $source->isCapturableByHand(),
            ], SupplierPriceSource::cases()),
        ]);
    }
}
