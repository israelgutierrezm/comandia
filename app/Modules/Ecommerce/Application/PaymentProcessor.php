<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Domain\Payments\CheckoutIntent;
use App\Modules\Ecommerce\Domain\Payments\RefundResult;
use App\Modules\Ecommerce\Domain\Payments\WebhookResult;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Shared\Domain\Events\EcommerceOrderPaid;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inicia el cobro y confirma el pago de un pedido (Iteración 8, Tanda C parte 3).
 *
 * Al iniciar, la pasarela activa crea el cobro y devuelve a dónde mandar al cliente. El webhook, ya firmado y verificado
 * por la pasarela, confirma el pago: se crea el `payment` (inmutable, idempotente por pasarela+referencia), el pedido pasa
 * a `paid` y se **emite `EcommerceOrderPaid`** DESPUÉS del commit, para que Finance asiente la venta e Inventory descuente
 * (ADR-004: el e-commerce no escribe en finanzas ni en el kardex; emite el hecho).
 */
final class PaymentProcessor
{
    public function __construct(
        private readonly PaymentGatewayFactory $factory,
        private readonly AcceptOrder $acceptOrder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Falla temprano (422) si el negocio no puede cobrar. Se llama ANTES de materializar el pedido: sin esto, un negocio
     * sin pasarela crearía el pedido y vaciaría el carrito para luego reventar al iniciar el cobro, dejando un pedido
     * huérfano y al cliente sin carrito.
     */
    public function ensureAvailable(): void
    {
        $this->factory->active($this->settings());
    }

    public function initiate(Order $order): CheckoutIntent
    {
        $settings = $this->settings();
        $gateway = $this->factory->active($settings);

        $intent = $gateway->createCheckout($order->load('store'), $settings);

        // La referencia con la que la pasarela identificará el pago se guarda para casar el webhook con el pedido.
        $order->update(['gateway' => $gateway->name(), 'gateway_reference' => $intent->reference]);

        return $intent;
    }

    /**
     * Confirma el pago desde el webhook. Devuelve el pedido si quedó pagado, o null (no aprobado / desconocido / repetido).
     */
    public function confirm(string $gatewayName, Request $request): ?Order
    {
        $settings = $this->settings();
        $gateway = $this->factory->for($gatewayName);

        // Verifica la firma y traduce el aviso; lanza si la firma es inválida.
        $result = $gateway->parseWebhook($request, $settings);

        if (! $result->approved) {
            return null;
        }

        // El pedido tiene que ser DE ESTA pasarela. Buscarlo sólo por referencia dejaba que el aviso de una pasarela
        // confirmara el cobro de otra: la de prueba —que no verifica nada— aprobaba un pedido de Stripe con sólo
        // nombrar su referencia, que el cliente ve en la URL de su propio pago.
        $order = Order::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $result->reference)
            ->first();

        if ($order === null) {
            return null;
        }

        // Idempotencia: un aviso repetido no re-procesa ni re-emite el evento.
        $already = Payment::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $result->reference)
            ->exists();

        // El pago llegó a un pedido que ya se cerró SIN cobrarse —venció, falló o se canceló antes de pagar— (D380). Antes
        // la máquina de estados lo rechazaba, el pago no quedaba en ningún lado y nadie lo devolvía: el cliente pagaba un
        // pedido que no se iba a preparar.
        //
        // Hoy ningún camino cancela un pedido ya pagado; si llega a existir, el reembolso que ese camino registre hace que
        // un aviso repetido de su pago original no se vuelva a reembolsar aquí.
        if ($order->status->endsUnpaid()) {
            return $this->refundLatePayment($order, $gatewayName, $result, $already);
        }

        if ($already) {
            return $order;
        }

        $pagado = DB::transaction(function () use ($order, $gatewayName, $result): ?Order {
            // El pedido se vuelve a leer BLOQUEADO: la tarea que vence pedidos pudo cerrarlo entre la lectura de arriba y
            // ésta. Sin el candado, el pago lo sobrescribía a «pagado» con su cupón ya devuelto (D380).
            $pedido = Order::query()->whereKey($order->id)->lockForUpdate()->sole();

            if ($pedido->status->endsUnpaid()) {
                return null;
            }

            Payment::create([
                'order_id' => $pedido->id,
                'gateway' => $gatewayName,
                'gateway_reference' => $result->reference,
                'gateway_payment_id' => $result->gatewayPaymentId, // el cargo en la pasarela, para poder reembolsar (D2 p2)
                'amount' => $pedido->total,
                'status' => 'approved',
                'confirmed_at' => now(),
            ]);

            // Por la máquina de estados: un aviso tardío sobre un pedido fallido, cancelado o ya aceptado no lo
            // «re-paga» ni vuelve a emitir la venta. `update()` directo se saltaba ese candado.
            $pedido->transitionTo(OnlineOrderStatus::Paid);
            $pedido->save();

            return $pedido->refresh();
        });

        // Perdió la carrera con el vencimiento: es un pago tardío y se trata como tal.
        if ($pagado === null) {
            return $this->refundLatePayment($order->refresh(), $gatewayName, $result, false);
        }

        $order = $pagado;

        // Después del commit: los oyentes ven el pedido pagado y el pago ya escritos (D220).
        EcommerceOrderPaid::dispatch(
            (int) $order->tenant_id,
            (int) $order->branch_id,
            $order->ulid,
            (int) $order->customer_id,
            $order->saleAmount(), // la venta es NETA de cupones (subtotal − discount_total); el diario suma por tipo (ADR-010)
            $order->total,
            $order->shipping_cost,
            $order->items->map(fn ($i): array => ['article_id' => (int) $i->article_id, 'quantity' => (int) $i->quantity])->all(),
            CarbonImmutable::now()->toIso8601String(),
        );

        // Aceptación automática (D51): si la tienda la tiene activa, el pedido pagado se acepta solo (y de ahí descuenta
        // inventario y genera comandas). Si no, espera en la bandeja a que el personal lo acepte.
        if ($order->store->auto_accept_orders) {
            $order = $this->acceptOrder->accept($order, actorMembershipId: null);
        }

        return $order;
    }

    /**
     * Reembolsa el cobro de un pedido con la pasarela con que se cobró (Iteración 8, Tanda D). Devuelve la referencia y el
     * monto del reembolso; el registro del `payment` de reembolso y la reversa del diario los hace {@see RejectOrder}.
     */
    public function refund(Order $order): RefundResult
    {
        $gateway = $this->factory->for((string) $order->gateway);

        $approved = Payment::query()
            ->where('order_id', $order->id)
            ->where('status', 'approved')
            ->firstOrFail();

        return $gateway->refund($order, $approved, $this->settings());
    }

    /**
     * Registra el pago tardío y lo reembolsa (D380).
     *
     * El pedido NO se revive: ya devolvió su cupón y puede que el cliente ya pidió otro. El pago queda registrado
     * —aprobado— y su reembolso al lado, y la bitácora lo dice. No toca el diario: la venta nunca se asentó, y lo que
     * entró por la pasarela sale por ella.
     *
     * Si la pasarela no puede reembolsar, se registra en la bitácora para que alguien lo devuelva a mano y se LANZA: el
     * webhook responde error, la pasarela reintenta el aviso, y el reintento —que encuentra el pago ya registrado— vuelve a
     * intentar sólo el reembolso.
     */
    private function refundLatePayment(Order $order, string $gatewayName, WebhookResult $result, bool $alreadyRecorded): Order
    {
        if (! $alreadyRecorded) {
            Payment::create([
                'order_id' => $order->id,
                'gateway' => $gatewayName,
                'gateway_reference' => $result->reference,
                'gateway_payment_id' => $result->gatewayPaymentId,
                'amount' => $order->total,
                'status' => 'approved',
                'confirmed_at' => now(),
            ]);
        }

        // Un aviso repetido no reembolsa dos veces.
        if (Payment::query()->where('order_id', $order->id)->where('status', 'refunded')->exists()) {
            return $order;
        }

        try {
            $refund = $this->refund($order);
        } catch (Throwable $e) {
            $this->audit->log(
                action: AuditAction::ONLINE_ORDER_LATE_PAYMENT_REFUND_FAILED,
                auditable: $order,
                after: [
                    'folio' => $order->folio(),
                    'status' => $order->status->value,
                    'amount' => $order->total,
                    'gateway' => $gatewayName,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ],
            );

            throw $e;
        }

        Payment::create([
            'order_id' => $order->id,
            'gateway' => $gatewayName,
            'gateway_reference' => $refund->reference,
            'amount' => $refund->amount,
            'status' => 'refunded',
            'confirmed_at' => now(),
        ]);

        $this->audit->log(
            action: AuditAction::ONLINE_ORDER_LATE_PAYMENT_REFUNDED,
            auditable: $order,
            after: [
                'folio' => $order->folio(),
                'status' => $order->status->value,
                'amount' => $refund->amount,
                'gateway' => $gatewayName,
                'refund_reference' => $refund->reference,
            ],
        );

        return $order;
    }

    private function settings(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::query()->first()
            ?? throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('El negocio no tiene pasarela de pago configurada.');
    }
}
