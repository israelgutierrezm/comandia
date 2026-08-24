<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\CouponRedemption;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Contracts\AreaRouter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Materializa el carrito en un pedido (Iteración 8, Tanda C parte 2).
 *
 * Congela los importes de línea y los totales (como el POS al comandar), folia el documento por sucursal sin huecos (§7) y
 * vacía el carrito. El pedido nace `pending_payment`; el pago (parte 3) lo lleva a `paid` y dispara el ciclo financiero.
 * La entrega es pickup o envío por zona (su costo suma al total).
 */
final class PlaceOrder
{
    private const SERIES = 'WEB';

    public function __construct(
        private readonly Cart $cart,
        private readonly DocumentNumberAllocator $folios,
        private readonly AreaRouter $areaRouter,
        private readonly ResolveCoupon $coupons,
    ) {}

    /**
     * @param  array{type: string, zone_ulid?: string|null, address?: string|null, notes?: string|null, coupon_code?: string|null}  $delivery
     */
    public function place(Store $store, Customer $customer, array $delivery): Order
    {
        $contents = $this->cart->contents($store);

        if ($contents['items'] === []) {
            throw new UnprocessableEntityHttpException('Tu carrito está vacío.');
        }

        // Ningún artículo agotado se pide.
        foreach ($contents['items'] as $line) {
            if ($line['out_of_stock']) {
                throw new UnprocessableEntityHttpException("«{$line['name']}» está agotado. Quítalo del carrito para continuar.");
            }
        }

        $branch = Branch::query()->where('ulid', $contents['branch_ulid'])->firstOrFail();

        // Entrega: pickup (sin costo) o envío por zona.
        $shippingCost = '0.00';
        $zoneId = null;
        $address = null;

        if ($delivery['type'] === 'shipping') {
            $zone = ShippingZone::query()
                ->where('store_id', $store->id)
                ->where('is_active', true)
                ->where('ulid', (string) ($delivery['zone_ulid'] ?? ''))
                ->first();

            if ($zone === null) {
                throw new UnprocessableEntityHttpException('Elige una zona de envío válida.');
            }

            $shippingCost = $zone->cost;
            $zoneId = $zone->id;
            $address = $delivery['address'] ?? null;

            if ($address === null || trim($address) === '') {
                throw new UnprocessableEntityHttpException('El envío necesita una dirección.');
            }
        }

        $subtotal = $contents['total'];

        // Cupón opcional: valida y calcula el descuento. El de productos reduce el subtotal a cobrar; el de envío gratis
        // pone el envío en cero (D342). El canje se registra dentro de la transacción, con el pedido ya creado.
        $discountTotal = '0.00';
        $couponId = null;
        $discount = null;
        $couponCode = trim((string) ($delivery['coupon_code'] ?? ''));

        if ($couponCode !== '') {
            $discount = $this->coupons->resolve($couponCode, $customer, $subtotal, $shippingCost, $delivery['type']);
            $discountTotal = $discount->productDiscount;
            $couponId = (int) $discount->coupon->id;

            if ($discount->waivesShipping) {
                $shippingCost = '0.00';
            }
        }

        $total = bcadd(bcsub($subtotal, $discountTotal, 2), $shippingCost, 2);

        return DB::transaction(function () use ($store, $customer, $branch, $delivery, $zoneId, $shippingCost, $address, $subtotal, $discountTotal, $couponId, $discount, $total, $contents): Order {
            // El folio y el pedido nacen o mueren juntos: sin huecos (§7).
            $number = $this->folios->next((int) $branch->id, 'ecommerce_order', self::SERIES);

            $order = Order::create([
                'store_id' => $store->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'series' => self::SERIES,
                'order_number' => $number,
                'delivery_type' => $delivery['type'],
                'shipping_zone_id' => $zoneId,
                'coupon_id' => $couponId,
                'shipping_cost' => $shippingCost,
                'delivery_address' => $address,
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'total' => $total,
                'status' => 'pending_payment',
                'notes' => $delivery['notes'] ?? null,
            ]);

            foreach ($contents['items'] as $line) {
                $article = Article::query()->where('ulid', $line['article_ulid'])->first();

                if ($article === null) {
                    throw new UnprocessableEntityHttpException("«{$line['name']}» ya no está disponible.");
                }

                $order->items()->create([
                    'article_id' => $article->id,
                    // El área se congela ahora (como el POS al capturar, D240): al aceptar se parte en comandas sin volver
                    // a resolver el ruteo. `null` es legítimo —ese item no se comanda—. Se pregunta por la sonda del kernel.
                    'preparation_area_id' => $this->areaRouter->routeForArticle((int) $article->id, (int) $branch->id),
                    'name' => $line['name'],
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);
            }

            // El canje del cupón: inmutable, uno por pedido (llave única), y el contador de usos sube atómicamente.
            if ($discount !== null) {
                CouponRedemption::create([
                    'coupon_id' => $couponId,
                    'order_id' => $order->id,
                    'customer_id' => $customer->id,
                    'amount_discounted' => $discount->amountDiscounted,
                    'redeemed_at' => CarbonImmutable::now(),
                ]);

                Coupon::query()->whereKey($couponId)->increment('uses_count');
            }

            $this->cart->clear();

            return $order->refresh();
        });
    }
}
