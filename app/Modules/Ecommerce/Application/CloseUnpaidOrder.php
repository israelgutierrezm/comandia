<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Domain\UnpaidOrderPolicy;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\CouponRedemption;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cierra un pedido que nunca se pagó y le devuelve su cupón (D380).
 *
 * ## Por qué hacía falta
 *
 * Un pedido nacía «pendiente de pago» y, si el cliente se iba sin pagar, se quedaba así para siempre: en la bandeja, en
 * «Mis pedidos» y, sobre todo, con su cupón GASTADO —el canje se registra al colocar el pedido—. Un cupón de «primer
 * pedido» que se abandonaba ya no se podía volver a usar, y uno de cien usos se acababa con carritos que nadie pagó.
 *
 * ## Cuándo
 *
 * Los plazos los fija {@see UnpaidOrderPolicy}: el enlace de pago vence a los 30 minutos (cada pasarela lo pone en el
 * cobro) y la tarea `comandia:online-orders:expire` cierra como «Pago vencido» lo que a los 45 sigue sin pago. Si aun así
 * llega un pago, se reembolsa (`PaymentProcessor`).
 *
 * El mismo cierre sirve para `failed` y para `cancelled` desde `pending_payment`: los tres devuelven el cupón.
 */
final class CloseUnpaidOrder
{
    /**
     * Cierra el pedido con `$to` si sigue pendiente de pago; si no (se pagó mientras tanto, ya se cerró), lo deja como
     * está. Es idempotente: correr la tarea dos veces no cierra dos veces ni devuelve el cupón dos veces.
     */
    public function close(Order $order, OnlineOrderStatus $to): Order
    {
        return DB::transaction(function () use ($order, $to): Order {
            // Bloqueado: un pago que se confirma a la vez tiene que verse aquí, o se vencería un pedido recién pagado.
            $pedido = Order::query()->whereKey($order->id)->lockForUpdate()->sole();

            if ($pedido->status !== OnlineOrderStatus::PendingPayment) {
                return $pedido;
            }

            $pedido->transitionTo($to);

            if ($to === OnlineOrderStatus::Expired) {
                $pedido->expired_at = CarbonImmutable::now();
            }

            $pedido->save();

            $this->releaseCoupon($pedido);

            return $pedido->refresh();
        });
    }

    /**
     * Devuelve el uso del cupón: el canje queda marcado y el contador del cupón baja uno.
     */
    private function releaseCoupon(Order $pedido): void
    {
        $canje = CouponRedemption::query()
            ->where('order_id', $pedido->id)
            ->whereNull('released_at')
            ->lockForUpdate()
            ->first();

        if ($canje === null) {
            return;
        }

        $canje->update(['released_at' => CarbonImmutable::now()]);

        // Nunca por debajo de cero: un contador que ya se corrigió a mano no se vuelve negativo.
        Coupon::query()->whereKey($canje->coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
    }
}
