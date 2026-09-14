<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Domain\Marketplace;

use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use Illuminate\Http\Request;

/**
 * El contrato de un canal de marketplace (ADR-015), calcado de {@see \App\Modules\Ecommerce\Domain\Payments\PaymentGateway}:
 * agregar un canal es implementar esto, no tocar la ingesta.
 *
 *  - `parseWebhook` verifica la firma y NORMALIZA el pedido entrante a {@see IngestedOrder}. Lanza si la
 *    firma no cuadra (como las pasarelas).
 *  - `acknowledge` avisa a la plataforma que el pedido se aceptó o rechazó (su repartidor entra en juego).
 */
interface MarketplaceChannel
{
    public function name(): string;

    public function parseWebhook(Request $request, DeliveryChannelSetting $settings): IngestedOrder;

    public function acknowledge(Order $order, DeliveryChannelSetting $settings, bool $accepted, string $reason = ''): void;
}
