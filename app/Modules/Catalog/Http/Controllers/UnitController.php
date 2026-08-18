<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreUnitRequest;
use App\Modules\Catalog\Http\Requests\UpdateUnitRequest;
use App\Modules\Catalog\Http\Resources\UnitResource;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Unidades de medida y sus conversiones (D22).
 *
 * La autorización va en la ruta: **lectura** con `catalog.articles.view` y **escritura** con
 * `catalog.units.manage`. Leer unidades con el permiso de ver artículos y no con uno propio es
 * deliberado: las unidades son datos de referencia del catálogo, cualquiera que capture una receta o
 * consulte un artículo las necesita, y el catálogo de permisos es cerrado (D10) — inventar
 * `catalog.units.view` sería agregar un permiso que nadie pidió.
 */
final class UnitController
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Unit>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['dimension' => 'dimension', 'status' => 'status'],
            sortable: ['code', 'name', 'created_at'],
            searchable: ['code', 'name'],
            defaultSort: 'code',
        );

        $units = $query
            ->apply(Unit::query(), $request)
            ->paginate($query->perPage($request));

        return UnitResource::collection($units);
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = Unit::create($request->validated());

        // `refresh()` para que los decimales salgan con la escala de la COLUMNA. Sin él, el `POST`
        // devolvía el factor tal como llegó —«12»— y el `GET` posterior «12.00000000»: el mismo valor con
        // dos formas según el endpoint, que es lo que obliga a un cliente a normalizar cadenas de dinero
        // por su cuenta. Lo destapó la primera prueba que llamó a este endpoint.
        $unit->refresh();

        // Sin bitácora técnica: §6.7 lista las acciones que la bitácora vigila —accesos,
        // configuración, usuarios y roles, acciones sensibles del POS, precios— y el catálogo de
        // unidades no está entre ellas. Registrar todo produce una bitácora que nadie lee, y el
        // valor de la bitácora está en que se pueda leer.
        return (new UnitResource($unit))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Unit $unit): UnitResource
    {
        return new UnitResource($unit);
    }

    /**
     * Sólo nombre y estado. El código, la magnitud y el factor son inmutables: cambiarlos
     * reinterpretaría todas las cantidades ya capturadas en esta unidad. Ver
     * {@see UpdateUnitRequest}.
     */
    public function update(UpdateUnitRequest $request, Unit $unit): UnitResource
    {
        $unit->update($request->validated());

        return new UnitResource($unit->refresh());
    }
}
