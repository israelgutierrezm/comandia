<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Shared\Domain\Events\EcommerceOrderRefunded;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Rechaza un pedido pagado y lo reembolsa (Iteración 8, Tanda D, D2).
 *
 * Sólo se rechaza un pedido **pagado y aún no aceptado**: como el inventario se descuenta al aceptar (D338), un pedido sin
 * aceptar nunca movió stock, así que el rechazo **no toca el kardex** —el pago limpio de esa decisión—. El reembolso va
 * PRIMERO (llamada a la pasarela); si falla, el pedido sigue pagado y el personal reintenta. Confirmado el reembolso, el
 * pedido pasa a `rejected`, se registra un `payment` inmutable de reembolso y se emite `EcommerceOrderRefunded` para que
 * Finance reverse la venta en el diario (ADR-010 regla 4). Idempotente por la máquina de estados: sólo un pedido `paid`
 * entra, así que un segundo rechazo choca antes de volver a reembolsar.
 */
final class RejectOrder
{
    public function __construct(private readonly PaymentProcessor $payments) {}

    public function reject(Order $order, string $reason): Order
    {
        if ($order->status !== OnlineOrderStatus::Paid) {
            throw new UnprocessableEntityHttpException('Sólo un pedido pagado y aún no aceptado se puede rechazar.');
        }

        // El reembolso primero: si la pasarela falla, no se toca el pedido y se reintenta.
        $refund = $this->payments->refund($order);

        $refundPaymentUlid = '';

        $rejected = DB::transaction(function () use ($order, $reason, $refund, &$refundPaymentUlid): Order {
            $order->transitionTo(OnlineOrderStatus::Rejected);
            $order->rejected_at = CarbonImmutable::now();
            $order->rejection_reason = $reason;
            $order->save();

            $refundPaymentUlid = Payment::create([
                'order_id' => $order->id,
                'gateway' => (string) $order->gateway,
                'gateway_reference' => $refund->reference,
                'amount' => $refund->amount,
                'status' => 'refunded',
                'confirmed_at' => CarbonImmutable::now(),
            ])->ulid;

            return $order->refresh();
        });

        // Tras el commit: Finance ve el pedido rechazado y el pago de reembolso ya escritos (D220).
        EcommerceOrderRefunded::dispatch(
            (int) $rejected->tenant_id,
            (int) $rejected->branch_id,
            $rejected->ulid,
            $refundPaymentUlid,
            $rejected->saleAmount(), // se reversa la venta NETA de cupones, igual que se asentó
            CarbonImmutable::now()->toIso8601String(),
        );

        return $rejected;
    }
}
