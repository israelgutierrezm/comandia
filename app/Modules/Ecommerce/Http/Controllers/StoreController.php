<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\ManageStore;
use App\Modules\Ecommerce\Http\Requests\SaveStoreRequest;
use App\Modules\Ecommerce\Http\Resources\StoreResource;
use Illuminate\Http\JsonResponse;

/**
 * Configuración de la tienda en línea (Iteración 8, Tanda B). Gateado por `module:Ecommerce` (un negocio sin la tienda no
 * ejecuta esto) y `ecommerce.store.configure`. Una tienda por negocio; el cliente elige, al comprar, entre las sucursales
 * que la tienda atiende.
 */
final class StoreController
{
    public function __construct(private readonly ManageStore $stores) {}

    public function show(): JsonResponse
    {
        $store = $this->stores->current();

        return new JsonResponse([
            'data' => $store === null ? null : new StoreResource($store->load('storeBranches.branch')),
        ]);
    }

    public function update(SaveStoreRequest $request): JsonResponse
    {
        $store = $this->stores->save(
            [
                'slug' => (string) $request->string('slug'),
                'name' => (string) $request->string('name'),
                'is_active' => $request->boolean('is_active'),
                'theme_primary' => (string) $request->string('theme_primary'),
                'auto_accept_orders' => $request->boolean('auto_accept_orders'),
            ],
            $request->array('branch_ulids'),
        );

        return new JsonResponse(['data' => new StoreResource($store->load('storeBranches.branch'))]);
    }
}
