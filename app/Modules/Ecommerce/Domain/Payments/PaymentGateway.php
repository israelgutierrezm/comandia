<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Payments;

use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use Illuminate\Http\Request;

/**
 * Contrato único de pasarela de pago (Iteración 8, Tanda C, ADR-007, D49).
 *
 * Dos implementaciones —Mercado Pago y Stripe— y una tercera de prueba. Una activa por negocio. El checkout no sabe cuál
 * es: pide crear el cobro y recibe una URL a la que mandar al cliente. El webhook llega firmado; cada pasarela verifica su
 * firma y traduce el aviso al mismo resultado. **Agregar una pasarela es implementar este contrato**, no tocar el checkout.
 */
interface PaymentGateway
{
    /** Identificador de la pasarela: 'mercadopago' | 'stripe' | 'fake'. */
    public function name(): string;

    /**
     * Crea el cobro en la pasarela y devuelve a dónde mandar al cliente y con qué referencia se le dará seguimiento.
     */
    public function createCheckout(Order $order, PaymentGatewaySetting $settings): CheckoutIntent;

    /**
     * Verifica la firma del webhook y traduce el aviso a un resultado uniforme. Lanza si la firma no es válida.
     */
    public function parseWebhook(Request $request, PaymentGatewaySetting $settings): WebhookResult;
}
