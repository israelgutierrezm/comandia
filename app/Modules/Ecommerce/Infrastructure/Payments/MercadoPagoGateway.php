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
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pasarela Mercado Pago (Iteración 8, Tanda C parte 3b) sobre el mismo contrato que el resto.
 *
 * Checkout Pro: se crea una preferencia con el total del pedido y se manda al cliente a su `init_point`. El aviso llega
 * **firmado** (D55): se verifica el HMAC del encabezado `x-signature` —sobre el manifiesto `id:…;request-id:…;ts:…;` que
 * exige Mercado Pago— antes de creer nada. El aviso sólo trae el id del pago, así que se **consulta el pago** para leer su
 * estado, su monto y la `external_reference` (el ULID del pedido, que es la referencia que casa el pago con el pedido).
 *
 * Sin SDK: el cliente HTTP de Laravel basta y deja la firma y la consulta explícitas y fáciles de doblar en pruebas.
 *
 * Simplificación v1 declarada: un solo renglón por el total del pedido, moneda MXN.
 */
final class MercadoPagoGateway implements PaymentGateway
{
    private const API = 'https://api.mercadopago.com';

    public function name(): string
    {
        return 'mercadopago';
    }

    public function createCheckout(Order $order, PaymentGatewaySetting $settings): CheckoutIntent
    {
        $slug = $order->store->slug;
        $folio = $order->folio();

        $response = Http::withToken($settings->secret_key)
            ->post(self::API.'/checkout/preferences', [
                'items' => [[
                    'title' => "Pedido {$folio}",
                    'quantity' => 1,
                    'currency_id' => 'MXN',
                    'unit_price' => (float) $order->total,
                ]],
                'external_reference' => $order->ulid,
                'notification_url' => url("/t/{$slug}/webhook/mercadopago"),
                'back_urls' => [
                    'success' => url("/t/{$slug}?pedido={$folio}"),
                    'failure' => url("/t/{$slug}"),
                    'pending' => url("/t/{$slug}"),
                ],
            ]);

        if (! $response->successful() || ! is_string($response->json('init_point'))) {
            throw new UnprocessableEntityHttpException('Mercado Pago no pudo crear el cobro.');
        }

        return new CheckoutIntent(
            redirectUrl: (string) $response->json('init_point'),
            reference: $order->ulid,
        );
    }

    public function parseWebhook(Request $request, PaymentGatewaySetting $settings): WebhookResult
    {
        $paymentId = (string) $request->input('data.id');

        $this->verifySignature($request, $paymentId, (string) $settings->webhook_secret);

        // El aviso sólo trae el id: se consulta el pago para conocer su verdad (estado, monto y referencia).
        $payment = Http::withToken($settings->secret_key)->get(self::API."/v1/payments/{$paymentId}");

        if (! $payment->successful()) {
            throw new UnprocessableEntityHttpException('No se pudo consultar el pago en Mercado Pago.');
        }

        return new WebhookResult(
            reference: (string) $payment->json('external_reference', ''),
            approved: $payment->json('status') === 'approved',
            amount: Decimal::round((string) $payment->json('transaction_amount', '0'), 2),
        );
    }

    public function refund(Order $order, Payment $payment, PaymentGatewaySetting $settings): RefundResult
    {
        // El reembolso real necesita el id del pago de Mercado Pago, que la parte 3b no guardó (guardó el ULID del pedido
        // como referencia externa). Capturarlo al confirmar y llamar a `POST /v1/payments/{id}/refunds` llega en la parte 2.
        throw new UnprocessableEntityHttpException(
            'El reembolso automático por Mercado Pago llega en la siguiente entrega. Reembolsa desde el panel de Mercado Pago por ahora.',
        );
    }

    /**
     * Verifica el HMAC del encabezado `x-signature` (`ts=…,v1=…`) sobre el manifiesto que exige Mercado Pago:
     * `id:{data.id};request-id:{x-request-id};ts:{ts};`. Lanza si falta o no cuadra (D55).
     */
    private function verifySignature(Request $request, string $paymentId, string $secret): void
    {
        $parts = [];
        foreach (explode(',', (string) $request->header('x-signature', '')) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k] = $v;
        }

        $ts = $parts['ts'] ?? '';
        $given = $parts['v1'] ?? '';
        $requestId = (string) $request->header('x-request-id', '');

        if ($ts === '' || $given === '' || $paymentId === '' || $secret === '') {
            throw new BadRequestHttpException('Webhook de Mercado Pago sin firma.');
        }

        // El id alfanumérico va en minúsculas en el manifiesto, según la documentación de Mercado Pago.
        $manifest = sprintf('id:%s;request-id:%s;ts:%s;', mb_strtolower($paymentId), $requestId, $ts);
        $expected = hash_hmac('sha256', $manifest, $secret);

        if (! hash_equals($expected, $given)) {
            throw new BadRequestHttpException('Firma de webhook de Mercado Pago inválida.');
        }
    }
}
