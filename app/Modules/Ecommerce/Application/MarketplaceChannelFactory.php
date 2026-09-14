<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use App\Modules\Ecommerce\Domain\Marketplace\MarketplaceChannel;
use App\Modules\Ecommerce\Infrastructure\Marketplace\DidiFoodChannel;
use App\Modules\Ecommerce\Infrastructure\Marketplace\FakeMarketplaceChannel;
use App\Modules\Ecommerce\Infrastructure\Marketplace\RappiChannel;
use App\Modules\Ecommerce\Infrastructure\Marketplace\UberEatsChannel;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resuelve el adaptador de un canal de marketplace por su nombre (ADR-015), calcado de
 * `PaymentGatewayFactory`: un mapa estático, no un registro en BD. Los canales reales devuelven su stub
 * (que avisa "no cableado" hasta la Fase 3); `fake` es el adaptador de pruebas.
 */
final class MarketplaceChannelFactory
{
    /** @var array<string, class-string<MarketplaceChannel>> */
    private array $channels = [
        DeliveryChannelType::DidiFood->value => DidiFoodChannel::class,
        DeliveryChannelType::UberEats->value => UberEatsChannel::class,
        DeliveryChannelType::Rappi->value => RappiChannel::class,
        DeliveryChannelType::Fake->value => FakeMarketplaceChannel::class,
    ];

    public function for(string $name): MarketplaceChannel
    {
        if (! isset($this->channels[$name])) {
            throw new UnprocessableEntityHttpException("Canal de marketplace desconocido: {$name}.");
        }

        return app($this->channels[$name]);
    }
}
