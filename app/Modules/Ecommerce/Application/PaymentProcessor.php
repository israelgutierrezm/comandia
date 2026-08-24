<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Payments\CheckoutIntent;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Shared\Domain\Events\EcommerceOrderPaid;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly PaymentGatewayFactory $factory) {}

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

        $order = Order::query()->where('gateway_reference', $result->reference)->first();

        if ($order === null) {
            return null;
        }

        // Idempotencia: un aviso repetido no re-procesa ni re-emite el evento.
        $already = Payment::query()
            ->where('gateway', $gatewayName)
            ->where('gateway_reference', $result->reference)
            ->exists();

        if ($already) {
            return $order;
        }

        $order = DB::transaction(function () use ($order, $gatewayName, $result): Order {
            Payment::create([
                'order_id' => $order->id,
                'gateway' => $gatewayName,
                'gateway_reference' => $result->reference,
                'amount' => $order->total,
                'status' => 'approved',
                'confirmed_at' => now(),
            ]);

            $order->update(['status' => 'paid']);

            return $order->refresh();
        });

        // Después del commit: los oyentes ven el pedido pagado y el pago ya escritos (D220).
        EcommerceOrderPaid::dispatch(
            (int) $order->tenant_id,
            (int) $order->branch_id,
            $order->ulid,
            (int) $order->customer_id,
            $order->subtotal,
            $order->total,
            $order->shipping_cost,
            $order->items->map(fn ($i): array => ['article_id' => (int) $i->article_id, 'quantity' => (int) $i->quantity])->all(),
            CarbonImmutable::now()->toIso8601String(),
        );

        return $order;
    }

    private function settings(): PaymentGatewaySetting
    {
        return PaymentGatewaySetting::query()->first()
            ?? throw new \Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException('El negocio no tiene pasarela de pago configurada.');
    }
}
