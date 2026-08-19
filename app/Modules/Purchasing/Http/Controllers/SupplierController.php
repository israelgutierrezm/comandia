<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use App\Modules\Purchasing\Http\Requests\UpdateSupplierRequest;
use App\Modules\Purchasing\Http\Resources\SupplierResource;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Proveedores (D26).
 *
 * **Sin endpoint de borrado**, y es deliberado: las recepciones y el historial de precios citan al proveedor, así que
 * borrarlo dejaría compras sin poder decir a quién se le compraron. Se da de baja con `status`, y las FK del historial
 * son RESTRICT para que la base lo impida además de la costumbre.
 */
final class SupplierController
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Supplier>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['legal_name', 'code', 'created_at'],
            // Por razón social, nombre comercial y código: son las tres formas en que alguien busca un proveedor, y la
            // tercera importa porque el código es lo que viene escrito en los papeles.
            //
            // `code` y `rfc` son `ascii_bin`, así que `ListQuery` los descarta cuando el término lleva acentos — es lo
            // que evita el 500 de D137, y no pierde resultados porque una columna ASCII no puede contener «Rodríguez».
            searchable: ['legal_name', 'trade_name', 'code', 'rfc'],
            defaultSort: 'legal_name',
        );

        $suppliers = $query->apply(Supplier::query(), $request);

        return SupplierResource::collection($suppliers->paginate($query->perPage($request)));
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = Supplier::create($request->validated());

        return (new SupplierResource($supplier->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier->refresh());
    }
}
