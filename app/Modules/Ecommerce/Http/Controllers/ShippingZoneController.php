<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Http\Requests\SaveShippingZoneRequest;
use App\Modules\Ecommerce\Http\Resources\ShippingZoneResource;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Zonas de envío de la tienda (Iteración 8, Tanda C parte 2). Admin, gateado por `module:Ecommerce` y
 * `ecommerce.shipping_zones.manage`. Las zonas cuelgan de la tienda del negocio.
 */
final class ShippingZoneController
{
    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, ShippingZone>>
     */
    public function index(): AnonymousResourceCollection
    {
        return ShippingZoneResource::collection(ShippingZone::query()->orderBy('name')->get());
    }

    public function store(SaveShippingZoneRequest $request): JsonResponse
    {
        $store = $this->requireStore();

        $zone = ShippingZone::create($request->validated() + ['store_id' => $store->id]);

        return new JsonResponse(['data' => new ShippingZoneResource($zone)], 201);
    }

    public function update(SaveShippingZoneRequest $request, ShippingZone $shippingZone): JsonResponse
    {
        $shippingZone->update($request->validated());

        return new JsonResponse(['data' => new ShippingZoneResource($shippingZone->refresh())]);
    }

    public function destroy(ShippingZone $shippingZone): JsonResponse
    {
        $shippingZone->delete();

        return new JsonResponse(status: 204);
    }

    private function requireStore(): Store
    {
        return Store::query()->first()
            ?? throw new UnprocessableEntityHttpException('Configura la tienda antes de agregar zonas de envío.');
    }
}
