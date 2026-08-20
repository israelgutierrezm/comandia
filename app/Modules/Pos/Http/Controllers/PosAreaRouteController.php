<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Domain\Exceptions\PosAreaRouteException;
use App\Modules\Pos\Http\Requests\StorePosAreaRouteRequest;
use App\Modules\Pos\Http\Resources\PosAreaRouteResource;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Qué área prepara qué (D240).
 *
 * ## Por qué usa el permiso de las áreas y no uno nuevo
 *
 * `organization.preparation_areas.manage`. Configurar qué manda a la cocina y qué a la barra **es** configurar las áreas:
 * quien puede crearlas y asignarles impresora es quien decide qué preparan.
 *
 * Y hay una razón operativa además de la conceptual: un permiso nuevo no existe para los negocios que ya corren, así que
 * su ruta responde 403 para todo el mundo hasta que alguien acuerde correr `comandia:permissions:sync` (D219). Reusar uno
 * existente evita ese hoyo sin inventar nada.
 */
final class PosAreaRouteController
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PosAreaRoute>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: [],
            sortable: ['created_at'],
            searchable: [],
            defaultSort: '-created_at',
            handledByCaller: ['branch', 'area'],
        );

        $builder = $query->apply(
            PosAreaRoute::query()->with(['branch', 'article', 'category', 'preparationArea']),
            $request,
        );

        if ($request->filled('branch')) {
            $builder->where('branch_id', Branch::findByUlid($request->string('branch')->toString())?->id);
        }

        if ($request->filled('area')) {
            $builder->whereHas('preparationArea', fn ($q) => $q->where('ulid', $request->string('area')));
        }

        return PosAreaRouteResource::collection($builder->paginate($query->perPage($request)));
    }

    public function store(StorePosAreaRouteRequest $request): JsonResponse
    {
        $branch = Branch::query()->where('ulid', $request->string('branch_ulid'))->sole();
        $area = PreparationArea::query()->where('ulid', $request->string('preparation_area_ulid'))->sole();

        // El área tiene que ser de la MISMA sucursal. Es la razón de que esta tabla exista en lugar de una columna en
        // `articles`: si se pudiera cruzar, las comandas de una sucursal saldrían por la impresora de otra y en la
        // primera nadie sabría por qué la cocina no recibe nada.
        if ((int) $area->branch_id !== (int) $branch->id) {
            throw PosAreaRouteException::areaFromAnotherBranch((string) $area->name, (string) $branch->name);
        }

        $route = PosAreaRoute::create([
            'branch_id' => $branch->id,
            'article_id' => $request->filled('article_ulid')
                ? Article::query()->where('ulid', $request->string('article_ulid'))->sole()->id
                : null,
            'article_category_id' => $request->filled('article_category_ulid')
                ? ArticleCategory::query()->where('ulid', $request->string('article_category_ulid'))->sole()->id
                : null,
            'preparation_area_id' => $area->id,
        ]);

        $this->audit->log(
            action: AuditAction::POS_AREA_ROUTE_CREATED,
            auditable: $route,
            after: [
                'branch' => $branch->name,
                'area' => $area->name,
                'article_id' => $route->article_id,
                'article_category_id' => $route->article_category_id,
            ],
        );

        return (new PosAreaRouteResource($this->loaded($route)))->response()->setStatusCode(201);
    }

    /**
     * Borra la regla.
     *
     * ## Y sí se borra, en un sistema donde casi nada se borra
     *
     * Porque no es un hecho, es configuración: «las bebidas van a la barra» no describe algo que ocurrió, describe cómo
     * se rutea de ahora en adelante. Los items ya capturados llevan su área **congelada** en la línea (D240), así que
     * quitar la regla no reescribe ninguna comanda ya emitida ni cambia a dónde iba lo que está en la cocina.
     *
     * Queda en la bitácora, que es donde se contesta «¿por qué las bebidas dejaron de salir en la barra?».
     */
    public function destroy(PosAreaRoute $posAreaRoute): JsonResponse
    {
        $this->audit->log(
            action: AuditAction::POS_AREA_ROUTE_DELETED,
            auditable: $posAreaRoute,
            before: [
                'branch_id' => $posAreaRoute->branch_id,
                'preparation_area_id' => $posAreaRoute->preparation_area_id,
                'article_id' => $posAreaRoute->article_id,
                'article_category_id' => $posAreaRoute->article_category_id,
            ],
        );

        $posAreaRoute->delete();

        return new JsonResponse(null, 204);
    }

    private function loaded(PosAreaRoute $route): PosAreaRoute
    {
        return $route->load(['branch', 'article', 'category', 'preparationArea']);
    }
}
