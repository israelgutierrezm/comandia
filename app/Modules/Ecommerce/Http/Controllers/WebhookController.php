<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\PaymentProcessor;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook de pago de la tienda (Iteración 8, Tanda C parte 3), sin autenticación —lo llama la pasarela—.
 *
 * El slug resuelve el negocio y fija el contexto; el procesador verifica la firma (cada pasarela la suya), y si el pago
 * está aprobado crea el `payment` (idempotente) y pasa el pedido a `paid`. Responde 200 siempre que el aviso se procese
 * —aprobado o no—, para que la pasarela no reintente en bucle; una firma inválida sí revienta (no es un aviso legítimo).
 * Exento de CSRF (la pasarela no trae token) — ver `bootstrap/app.php`.
 */
final class WebhookController
{
    use ResolvesPublicStore;

    public function __construct(private readonly PaymentProcessor $payments) {}

    public function handle(Request $request, string $slug, string $gateway): JsonResponse
    {
        $this->resolveStore($slug);

        $order = $this->payments->confirm($gateway, $request);

        return new JsonResponse(['status' => $order?->status ?? 'ignored']);
    }
}
