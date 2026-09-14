<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Infrastructure\Models\DeliveryChannelSetting;
use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Contracts\AreaRouter;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Domain\Events\EcommerceOrderPaid;
use App\Modules\Shared\Domain\Events\MarketplaceCommissionCharged;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ingiere un pedido de un marketplace (ADR-015): el gemelo de {@see PlaceOrder} + {@see PaymentProcessor}
 * para el canal de agregador. El adaptador del canal normaliza el webhook; aquí se traduce a un `Order`,
 * se marca **pagado** (la plataforma ya cobró) y se **auto-acepta** (sale a cocina), reusando los eventos
 * `EcommerceOrderPaid`/`EcommerceOrderAccepted` para que Finanzas, Inventario, Impresión y KDS reaccionen
 * sin cambios. Idempotente por (canal, id externo): un webhook reenviado no duplica el pedido.
 *
 * El tenant ya lo abrió el controlador del webhook desde el slug (ADR-002). El precio lo pone Comandia
 * (el catálogo es la verdad), no lo que reporte la plataforma.
 */
final class IngestMarketplaceOrder
{
    private const SERIES = 'MKT';

    public function __construct(
        private readonly MarketplaceChannelFactory $channels,
        private readonly DocumentNumberAllocator $folios,
        private readonly AreaRouter $areaRouter,
        private readonly AcceptOrder $acceptOrder,
    ) {}

    public function ingest(string $channelName, Request $request): Order
    {
        $channel = $this->channels->for($channelName);

        // La config del canal para este negocio. Debe estar encendida y configurada; si no, el canal no opera.
        $setting = DeliveryChannelSetting::query()
            ->where('channel', $channelName)
            ->where('is_active', true)
            ->first();

        if ($setting === null) {
            throw new NotFoundHttpException("El canal «{$channelName}» no está configurado o está apagado para esta tienda.");
        }

        // El adaptador verifica la firma (los reales) y normaliza el pedido.
        $ingested = $channel->parseWebhook($request, $setting);

        if (trim($ingested->externalOrderId) === '') {
            throw new UnprocessableEntityHttpException('El pedido del marketplace no trae identificador.');
        }

        // Idempotencia: un pedido ya ingerido (mismo canal + id externo) se devuelve, no se duplica.
        $existing = Order::query()
            ->where('channel', $channelName)
            ->where('external_order_id', $ingested->externalOrderId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $branch = Branch::query()->findOrFail($setting->branch_id);
        $store = Store::query()->firstOrFail(); // una tienda por negocio (ADR-013)

        $order = DB::transaction(function () use ($store, $branch, $channelName, $ingested): Order {
            $customer = $this->resolveCustomer($ingested);
            $number = $this->folios->next((int) $branch->id, 'ecommerce_order', self::SERIES);

            // Traducir cada línea a un artículo y CONGELAR su precio (como el POS al comandar).
            $lines = [];
            $subtotal = '0.00';

            foreach ($ingested->items as $item) {
                $map = MarketplaceMenuMap::query()
                    ->where('channel', $channelName)
                    ->where('external_item_id', $item->externalItemId)
                    ->first();

                if ($map === null) {
                    throw new UnprocessableEntityHttpException(
                        "El ítem «{$item->externalItemId}» del marketplace no está mapeado a ningún artículo.",
                    );
                }

                $article = Article::query()->find($map->article_id);

                if ($article === null) {
                    throw new UnprocessableEntityHttpException('Un artículo mapeado ya no existe.');
                }

                $unitPrice = $article->effectivePricingFor((int) $branch->id)->price;
                $lineTotal = bcmul($unitPrice, (string) $item->quantity, 2);
                $subtotal = bcadd($subtotal, $lineTotal, 2);

                $lines[] = [$article, $item->quantity, $unitPrice, $lineTotal];
            }

            if ($lines === []) {
                throw new UnprocessableEntityHttpException('El pedido del marketplace no trae partidas.');
            }

            $order = Order::create([
                'store_id' => $store->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'series' => self::SERIES,
                'order_number' => $number,
                'delivery_type' => 'marketplace',
                'shipping_cost' => '0.00',
                'subtotal' => $subtotal,
                'discount_total' => '0.00',
                'total' => $subtotal,
                // El pago es EXTERNO: la plataforma ya cobró al cliente. El pedido nace pagado.
                'status' => 'paid',
                'channel' => $channelName,
                'external_order_id' => $ingested->externalOrderId,
                'notes' => $ingested->notes,
            ]);

            foreach ($lines as [$article, $quantity, $unitPrice, $lineTotal]) {
                $order->items()->create([
                    'article_id' => $article->id,
                    // Es comida: se rutea a área como en preparación (marketplace ≠ envío retail).
                    'preparation_area_id' => $this->areaRouter->routeForArticle((int) $article->id, (int) $branch->id),
                    'name' => $article->displayName(),
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                ]);
            }

            return $order->refresh();
        });

        $this->emitPaidAndCommission($order, $setting->commission_rate, $channelName);

        // El marketplace SIEMPRE se auto-acepta: la plataforma ya confirmó el pedido; sale a cocina.
        $accepted = $this->acceptOrder->accept($order, actorMembershipId: null);

        $channel->acknowledge($accepted, $setting, accepted: true);

        return $accepted;
    }

    /**
     * Cliente del pedido: se reutiliza por teléfono (mismo cliente, repetido) o se crea uno «invitado» sin
     * credenciales (D43). El marketplace da el nombre y, a veces, el teléfono; no hay cuenta en Comandia.
     */
    private function resolveCustomer(\App\Modules\Ecommerce\Domain\Marketplace\IngestedOrder $ingested): Customer
    {
        $phone = $ingested->customerPhone !== null ? trim($ingested->customerPhone) : '';

        if ($phone !== '') {
            $existing = Customer::query()->where('phone', $phone)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return Customer::create([
            'name' => trim($ingested->customerName) !== '' ? $ingested->customerName : 'Cliente',
            'phone' => $phone !== '' ? $phone : null,
            'status' => 'active',
        ])->refresh();
    }

    /** Emite el hecho de venta pagada y, si hay tasa, la comisión del marketplace (post-commit, D220). */
    private function emitPaidAndCommission(Order $order, string $commissionRate, string $channelName): void
    {
        $now = CarbonImmutable::now();

        EcommerceOrderPaid::dispatch(
            (int) $order->tenant_id,
            (int) $order->branch_id,
            $order->ulid,
            (int) $order->customer_id,
            $order->saleAmount(),
            $order->total,
            $order->shipping_cost,
            $order->items->map(fn ($i): array => [
                'article_id' => (int) $i->article_id,
                'quantity' => (int) $i->quantity,
            ])->all(),
            $now->toIso8601String(),
        );

        // Comisión = venta × tasa%. `Decimal::divide` redondea (bcdiv a secas truncaría el residuo).
        $commission = Decimal::divide(bcmul($order->saleAmount(), $commissionRate, 4), '100', 2);

        if (bccomp($commission, '0.00', 2) === 1) {
            MarketplaceCommissionCharged::dispatch(
                (int) $order->tenant_id,
                (int) $order->branch_id,
                $order->ulid,
                $channelName,
                $commission,
                $now->toIso8601String(),
            );
        }
    }
}
