<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Marketplace;

use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use App\Modules\Ecommerce\Domain\Marketplace\IngestedOrder;
use App\Modules\Ecommerce\Domain\Marketplace\MarketplaceChannel;
use App\Modules\Ecommerce\Domain\Marketplace\MarketplaceChannelNotWired;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use Illuminate\Http\Request;

/**
 * DiDi Food (ADR-015). El contrato está listo; el adaptador real —firma del webhook, mapeo del payload y
 * callbacks— se cablea en la Fase 3, cuando exista el convenio y las credenciales. Hasta entonces avisa
 * claramente que no está cableado (503), en vez de fallar en silencio.
 */
final class DidiFoodChannel implements MarketplaceChannel
{
    public function name(): string
    {
        return DeliveryChannelType::DidiFood->value;
    }

    public function parseWebhook(Request $request, DeliveryChannelSetting $settings): IngestedOrder
    {
        throw new MarketplaceChannelNotWired(DeliveryChannelType::DidiFood->label());
    }

    public function acknowledge(Order $order, DeliveryChannelSetting $settings, bool $accepted, string $reason = ''): void
    {
        throw new MarketplaceChannelNotWired(DeliveryChannelType::DidiFood->label());
    }
}
