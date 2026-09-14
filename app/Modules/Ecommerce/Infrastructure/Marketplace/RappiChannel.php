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
 * Rappi (ADR-015). Contrato listo; adaptador real en la Fase 3 (convenio + credenciales).
 */
final class RappiChannel implements MarketplaceChannel
{
    public function name(): string
    {
        return DeliveryChannelType::Rappi->value;
    }

    public function parseWebhook(Request $request, DeliveryChannelSetting $settings): IngestedOrder
    {
        throw new MarketplaceChannelNotWired(DeliveryChannelType::Rappi->label());
    }

    public function acknowledge(Order $order, DeliveryChannelSetting $settings, bool $accepted, string $reason = ''): void
    {
        throw new MarketplaceChannelNotWired(DeliveryChannelType::Rappi->label());
    }
}
