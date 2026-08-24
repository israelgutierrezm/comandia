<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Domain\Coupons\CouponDiscount;
use App\Modules\Ecommerce\Domain\Enums\CouponType;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\CouponRedemption;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Valida un cupón para un cliente y calcula su descuento (Iteración 8, Tanda D, D3 parte 2).
 *
 * Comprueba, en orden: que exista y esté activo, que esté vigente, que no haya alcanzado su tope global, y que el cliente no
 * haya excedido su límite. Cada fallo es un 422 con un mensaje claro (el checkout lo muestra). El cálculo del descuento
 * separa productos de envío (D342): un porcentaje/monto reduce productos; el envío gratis pone el envío en cero y exige que
 * el pedido sea con envío.
 */
final class ResolveCoupon
{
    public function resolve(string $code, Customer $customer, string $subtotal, string $shippingCost, string $deliveryType): CouponDiscount
    {
        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw new UnprocessableEntityHttpException('Ese cupón no existe o no está activo.');
        }

        $today = CarbonImmutable::now();

        if ($coupon->valid_from !== null && $today->lt($coupon->valid_from)) {
            throw new UnprocessableEntityHttpException('Ese cupón todavía no es válido.');
        }

        if ($coupon->valid_until !== null && $today->gt($coupon->valid_until->endOfDay())) {
            throw new UnprocessableEntityHttpException('Ese cupón ya venció.');
        }

        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            throw new UnprocessableEntityHttpException('Ese cupón alcanzó su límite de usos.');
        }

        if ($coupon->per_customer_limit !== null) {
            $mine = CouponRedemption::query()
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $customer->id)
                ->count();

            if ($mine >= $coupon->per_customer_limit) {
                throw new UnprocessableEntityHttpException('Ya usaste ese cupón el máximo de veces.');
            }
        }

        return $this->discountFor($coupon, $subtotal, $shippingCost, $deliveryType);
    }

    private function discountFor(Coupon $coupon, string $subtotal, string $shippingCost, string $deliveryType): CouponDiscount
    {
        return match ($coupon->type) {
            CouponType::Percentage => new CouponDiscount(
                $coupon,
                productDiscount: $d = Decimal::round(Decimal::divide(bcmul($subtotal, (string) $coupon->value, 4), '100', 4), 2),
                waivesShipping: false,
                amountDiscounted: $d,
            ),

            // El monto fijo no puede descontar más que los productos.
            CouponType::Fixed => new CouponDiscount(
                $coupon,
                productDiscount: $f = (bccomp((string) $coupon->value, $subtotal, 2) > 0 ? Decimal::round($subtotal, 2) : Decimal::round((string) $coupon->value, 2)),
                waivesShipping: false,
                amountDiscounted: $f,
            ),

            CouponType::FreeShipping => $this->freeShipping($coupon, $shippingCost, $deliveryType),
        };
    }

    private function freeShipping(Coupon $coupon, string $shippingCost, string $deliveryType): CouponDiscount
    {
        if ($deliveryType !== 'shipping' || bccomp($shippingCost, '0', 2) <= 0) {
            throw new UnprocessableEntityHttpException('Ese cupón es de envío gratis: elige entrega a domicilio para usarlo.');
        }

        return new CouponDiscount(
            $coupon,
            productDiscount: '0.00',
            waivesShipping: true,
            amountDiscounted: Decimal::round($shippingCost, 2),
        );
    }
}
