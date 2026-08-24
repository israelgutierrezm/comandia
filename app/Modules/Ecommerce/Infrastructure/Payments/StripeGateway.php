<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Payments;

use App\Modules\Ecommerce\Domain\Payments\CheckoutIntent;
use App\Modules\Ecommerce\Domain\Payments\PaymentGateway;
use App\Modules\Ecommerce\Domain\Payments\WebhookResult;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pasarela Stripe (Iteración 8, Tanda C parte 3b) sobre el mismo contrato que el resto.
 *
 * Checkout alojado: se crea una Checkout Session con el total del pedido y se manda al cliente a la URL que Stripe
 * devuelve. El webhook `checkout.session.completed` llega **firmado** (D55): se verifica el HMAC del encabezado
 * `Stripe-Signature` antes de creer nada, y sólo entonces se traduce a `WebhookResult`. La **referencia** que casa el pago
 * con el pedido es el ULID del pedido, viajando en `client_reference_id` —no el id de Stripe—, para que el mismo valor que
 * se guarda al iniciar sea el que vuelve en el aviso.
 *
 * Sin SDK: el cliente HTTP de Laravel basta y deja la firma explícita y fácil de doblar en pruebas (`Http::fake`).
 *
 * Simplificación v1 declarada: un solo renglón por el total del pedido (no se replican los items ni impuestos en Stripe),
 * moneda MXN, y tolerancia de tiempo de la firma de 5 minutos.
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    /** Ventana de tolerancia de la marca de tiempo de la firma, en segundos, contra reenvíos viejos. */
    private const TOLERANCE = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(Order $order, PaymentGatewaySetting $settings): CheckoutIntent
    {
        $slug = $order->store->slug;
        $folio = $order->folio();

        // El monto va en la unidad mínima (centavos): DECIMAL(12,2) → entero, sin pasar por float.
        $amountCents = (int) bcmul($order->total, '100', 0);

        $response = Http::withToken($settings->secret_key)
            ->asForm()
            ->post(self::API.'/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $order->ulid,
                'success_url' => url("/t/{$slug}?pedido={$folio}"),
                'cancel_url' => url("/t/{$slug}"),
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => 'mxn',
                'line_items[0][price_data][unit_amount]' => $amountCents,
                'line_items[0][price_data][product_data][name]' => "Pedido {$folio}",
            ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            throw new UnprocessableEntityHttpException('Stripe no pudo crear el cobro.');
        }

        return new CheckoutIntent(
            redirectUrl: (string) $response->json('url'),
            reference: $order->ulid,
        );
    }

    public function parseWebhook(Request $request, PaymentGatewaySetting $settings): WebhookResult
    {
        $this->verifySignature($request, (string) $settings->webhook_secret);

        // Ya verificada la firma, el cuerpo es de fiar. Sólo `checkout.session.completed` con pago confirmado aprueba.
        $type = (string) $request->input('type');
        $session = $request->input('data.object', []);

        $approved = $type === 'checkout.session.completed'
            && ($session['payment_status'] ?? null) === 'paid';

        return new WebhookResult(
            reference: (string) ($session['client_reference_id'] ?? ''),
            approved: $approved,
            amount: Decimal::divide((string) ($session['amount_total'] ?? '0'), '100', 2),
        );
    }

    /**
     * Verifica el HMAC del encabezado `Stripe-Signature` (`t=…,v1=…`) sobre `{t}.{cuerpo crudo}`. Lanza si falta, si el
     * tiempo quedó fuera de tolerancia, o si el HMAC no cuadra. Es el candado de D55: sin firma válida no se cree el aviso.
     */
    private function verifySignature(Request $request, string $secret): void
    {
        $header = (string) $request->header('Stripe-Signature', '');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $given = $parts['v1'] ?? '';

        if ($timestamp === '' || $given === '' || $secret === '') {
            throw new BadRequestHttpException('Webhook de Stripe sin firma.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $given)) {
            throw new BadRequestHttpException('Firma de webhook de Stripe inválida.');
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE) {
            throw new BadRequestHttpException('Webhook de Stripe fuera de tiempo.');
        }
    }
}
