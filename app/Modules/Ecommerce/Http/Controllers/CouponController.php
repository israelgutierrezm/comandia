<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Http\Requests\SaveCouponRequest;
use App\Modules\Ecommerce\Http\Resources\CouponResource;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Cupones de la tienda (Iteración 8, Tanda D, D3). Admin, gateado por `module:Ecommerce` y `ecommerce.coupons.manage`. Los
 * cupones cuelgan de la tienda del negocio; el canje (parte 2) los aplica en el checkout.
 */
final class CouponController
{
    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, Coupon>>
     */
    public function index(): AnonymousResourceCollection
    {
        return CouponResource::collection(Coupon::query()->orderBy('code')->get());
    }

    public function store(SaveCouponRequest $request): JsonResponse
    {
        $store = $this->requireStore();

        $coupon = Coupon::create($request->validated() + ['store_id' => $store->id]);

        return new JsonResponse(['data' => new CouponResource($coupon->refresh())], 201);
    }

    public function update(SaveCouponRequest $request, Coupon $coupon): JsonResponse
    {
        $coupon->update($request->validated());

        return new JsonResponse(['data' => new CouponResource($coupon->refresh())]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        $coupon->delete();

        return new JsonResponse(status: 204);
    }

    private function requireStore(): Store
    {
        return Store::query()->first()
            ?? throw new UnprocessableEntityHttpException('Configura la tienda antes de crear cupones.');
    }
}
