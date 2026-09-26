<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Payments\PaymentGateway;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Payments\FakeGateway;
use App\Modules\Ecommerce\Infrastructure\Payments\MercadoPagoGateway;
use App\Modules\Ecommerce\Infrastructure\Payments\StripeGateway;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resuelve la implementación de pasarela por su nombre (Iteración 8, Tanda C). Agregar una pasarela es añadirla a este
 * mapa e implementar el contrato —el checkout y el webhook no cambian (ADR-007)—.
 */
final class PaymentGatewayFactory
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $gateways = [
        'mercadopago' => MercadoPagoGateway::class,
        'stripe' => StripeGateway::class,
        'fake' => FakeGateway::class,
    ];

    public function for(string $name): PaymentGateway
    {
        if (! in_array($name, $this->available(), true)) {
            throw new UnprocessableEntityHttpException("Pasarela «{$name}» no soportada.");
        }

        return app($this->gateways[$name]);
    }

    /**
     * Las pasarelas que este despliegue ofrece. La de prueba sólo si la configuración la enciende: aprueba cualquier
     * pedido que se le nombre, así que fuera de desarrollo sería cobrar sin cobrar (`comandia.payments`).
     *
     * @return list<string>
     */
    public function available(): array
    {
        $nombres = array_keys($this->gateways);

        return config('comandia.payments.fake_gateway_enabled')
            ? $nombres
            : array_values(array_diff($nombres, ['fake']));
    }

    public function active(PaymentGatewaySetting $settings): PaymentGateway
    {
        if ($settings->active_gateway === null) {
            throw new UnprocessableEntityHttpException('El negocio no tiene una pasarela de pago configurada.');
        }

        return $this->for($settings->active_gateway);
    }
}
