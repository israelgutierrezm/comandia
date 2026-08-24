<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Payments\PaymentGateway;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Payments\FakeGateway;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resuelve la implementación de pasarela por su nombre (Iteración 8, Tanda C). Agregar una pasarela es añadirla a este
 * mapa e implementar el contrato —el checkout y el webhook no cambian (ADR-007)—. Mercado Pago y Stripe se registran en la
 * parte 3b.
 */
final class PaymentGatewayFactory
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $gateways = [
        'fake' => FakeGateway::class,
    ];

    public function for(string $name): PaymentGateway
    {
        $class = $this->gateways[$name] ?? throw new UnprocessableEntityHttpException("Pasarela «{$name}» no soportada.");

        return app($class);
    }

    public function active(PaymentGatewaySetting $settings): PaymentGateway
    {
        if ($settings->active_gateway === null) {
            throw new UnprocessableEntityHttpException('El negocio no tiene una pasarela de pago configurada.');
        }

        return $this->for($settings->active_gateway);
    }
}
