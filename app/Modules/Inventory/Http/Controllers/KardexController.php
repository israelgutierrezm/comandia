<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

/**
 * El kardex de un artículo: todo lo que le pasó, del último movimiento hacia atrás.
 *
 * ## Paginación por CURSOR, no por número de página
 *
 * Es la tabla que más crece del sistema —cada venta descuenta insumos— y nadie audita un inventario saltando a
 * la página 400. Con `OFFSET`, esa página obligaría a MySQL a contar y descartar cuatrocientas páginas de
 * movimientos en cada consulta. Mismo criterio que la bitácora de auditoría (§8).
 *
 * ## Por qué se ordena por `occurred_at` y también por `id`
 *
 * Dos movimientos pueden compartir `occurred_at`: el mismo segundo, o la misma fecha capturada a mano en una
 * carga inicial. Sin desempate, el orden sería el que MySQL quisiera y la columna de saldo **parecería ir
 * hacia atrás** — el defecto más desconcertante posible en algo que se lee como un estado de cuenta.
 */
final class KardexController
{
    /**
     * @return AnonymousResourceCollection<CursorPaginator<int, StockMovement>>
     */
    public function __invoke(Request $request, Article $article): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [
                // El reporte «¿cuánto salió por consumo interno este mes?»: un filtro por tipo, servido por
                // `stock_movements_tenant_kind_index`.
                'kind' => 'kind',
                'direction' => 'direction',
            ],
            // Sólo por fecha, y a propósito: cualquier otro orden sobre esta tabla sería un `filesort` sobre
            // millones de filas. Los cuatro índices terminan en `occurred_at` justamente por esto.
            sortable: ['occurred_at'],
            searchable: [],
            // Descendente: un kardex se lee del presente al pasado.
            //
            // Estaba declarado ascendente y corregido después con un `reorder`, que es el parche que D182 quitó de la
            // bitácora y del historial de precios — allá se escribió porque `ListQuery` no sabía leer el prefijo `-`.
            // Ya lo sabe, así que el parche sobra: la declaración es ahora la única fuente del orden, y el desempate
            // por llave lo pone `ListQuery` — que es lo que hace estable el cursor cuando dos movimientos caen en el
            // mismo instante, que en un POS es lo normal.
            defaultSort: '-occurred_at',
            dateRanges: ['occurred' => 'occurred_at'],
            handledByCaller: ['warehouse'],
        );

        $builder = $query->apply(
            StockMovement::query()
                ->where('article_id', $article->id)
                // `wasteReason` va aquí porque el kardex es el ÚNICO sitio donde se leen las mermas: no hay endpoint
                // «de mermas» y no debe haberlo (una merma es un movimiento con motivo, D168). Sin cargarla, el recurso
                // omitía el campo por `whenLoaded` y la pantalla mostraba «sin motivo» — afirmando lo contrario de la
                // regla más firme del módulo, que es que una merma sin motivo no puede existir (§6.2).
                ->with(['warehouse', 'lot', 'actor.user', 'actor.employeeProfile', 'wasteReason']),
            $request,
        );

        // Sin filtro de almacén, el kardex es el del artículo en TODO el negocio. Es una vista legítima —«¿dónde
        // se movió mi queso?»— y por eso el almacén es opcional en lugar de obligatorio.
        if ($request->filled('warehouse')) {
            $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse'))->sole();
            $builder->where('warehouse_id', $warehouse->id);
        }

        $movements = $builder->cursorPaginate($query->perPage($request));

        return StockMovementResource::collection($movements);
    }

    /**
     * Los tipos de movimiento con su etiqueta, para que el cliente arme el filtro sin inventar el catálogo.
     *
     * Existe por la lección de D139: una lista de etiquetas escrita a mano en el cliente acaba diciendo algo
     * distinto de lo que dice el servidor, y nadie lo nota hasta que las dos aparecen en la misma pantalla.
     *
     * @return AnonymousResourceCollection<Collection<int, array<string, string>>>
     */
    public function kinds(): AnonymousResourceCollection
    {
        return JsonResource::collection(
            collect(StockMovementKind::cases())->map(fn (StockMovementKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'direction' => $kind->fixedDirection()?->value,
            ])
        );
    }
}
