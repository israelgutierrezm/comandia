<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Payments;

use App\Modules\Ecommerce\Domain\Payments\CheckoutIntent;
use App\Modules\Ecommerce\Domain\Payments\PaymentGateway;
use App\Modules\Ecommerce\Domain\Payments\RefundResult;
use App\Modules\Ecommerce\Domain\Payments\WebhookResult;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Payment;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use Illuminate\Http\Request;

/**
 * Pasarela de prueba (Iteración 8, Tanda C parte 3a).
 *
 * NO cobra: existe para ejercitar todo el flujo —crear cobro → página de pago → webhook → pedido pagado → ciclo
 * financiero— sin depender de un servicio externo, y para demostrarlo en desarrollo. Mercado Pago y Stripe reales
 * implementan el MISMO contrato (parte 3b). La referencia es el ULID del pedido; el webhook trae `reference` y `approved`.
 */
final class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(Order $order, PaymentGatewaySetting $settings): CheckoutIntent
    {
        $slug = $order->store->slug;

        return new CheckoutIntent(
            redirectUrl: url("/t/{$slug}/fake-pay/{$order->ulid}"),
            reference: $order->ulid,
        );
    }

    public function parseWebhook(Request $request, PaymentGatewaySetting $settings): WebhookResult
    {
        return new WebhookResult(
            reference: (string) $request->input('reference'),
            approved: $request->boolean('approved'),
            amount: (string) $request->input('amount', '0'),
            gatewayPaymentId: (string) $request->input('reference'), // sin cargo real: el id del pago es la propia referencia
        );
    }

    public function refund(Order $order, Payment $payment, PaymentGatewaySetting $settings): RefundResult
    {
        // No cobra ni devuelve nada: la referencia del reembolso es la del cobro con sufijo, para que sea única y estable.
        return new RefundResult(
            reference: $payment->gateway_reference.'-refund',
            amount: (string) $payment->amount,
        );
    }
}
