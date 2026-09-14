<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Infrastructure\Marketplace;

use App\Modules\Ecommerce\Domain\Marketplace\IngestedOrder;
use App\Modules\Ecommerce\Domain\Marketplace\IngestedOrderItem;
use App\Modules\Ecommerce\Domain\Marketplace\MarketplaceChannel;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use Illuminate\Http\Request;

/**
 * Adaptador de PRUEBAS del marketplace (ADR-015), gemelo de `FakeGateway`: sin firma ni API, lee el
 * pedido directo del cuerpo. Deja ejercitar todo el pipeline de ingesta sin credenciales reales.
 *
 * Forma del cuerpo:
 * `{ external_order_id, external_store_id?, customer_name, customer_phone?, notes?,
 *    items: [{ external_item_id, quantity, notes? }] }`
 */
final class FakeMarketplaceChannel implements MarketplaceChannel
{
    public function name(): string
    {
        return 'fake';
    }

    public function parseWebhook(Request $request, DeliveryChannelSetting $settings): IngestedOrder
    {
        $items = [];

        foreach ((array) $request->input('items', []) as $line) {
            $items[] = new IngestedOrderItem(
                externalItemId: (string) ($line['external_item_id'] ?? ''),
                quantity: (int) ($line['quantity'] ?? 0),
                notes: isset($line['notes']) ? (string) $line['notes'] : null,
            );
        }

        return new IngestedOrder(
            externalOrderId: (string) $request->input('external_order_id', ''),
            customerName: (string) $request->input('customer_name', 'Cliente'),
            items: $items,
            externalStoreId: $this->stringOrNull($request->input('external_store_id')),
            customerPhone: $this->stringOrNull($request->input('customer_phone')),
            notes: $this->stringOrNull($request->input('notes')),
            reportedTotal: $this->stringOrNull($request->input('total')),
        );
    }

    public function acknowledge(Order $order, DeliveryChannelSetting $settings, bool $accepted, string $reason = ''): void
    {
        // Adaptador de pruebas: no hay plataforma a la cual avisar.
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
