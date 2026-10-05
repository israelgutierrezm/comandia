<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\IngestMarketplaceOrder;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook de un marketplace de delivery (ADR-015), sin autenticación —lo llama la plataforma—.
 *
 * Gemelo de {@see WebhookController}: el slug resuelve el negocio y fija el contexto (y verifica el módulo);
 * el adaptador del canal verifica la firma y normaliza el pedido; la ingesta lo materializa, lo marca
 * pagado y lo auto-acepta (sale a cocina). Idempotente por (canal, id externo). Exento de CSRF (la
 * plataforma no trae token): la ruta cae bajo el patrón de webhooks eximido en `bootstrap/app.php`.
 *
 * La tienda en línea tiene que EXISTIR —su slug es la dirección que el negocio registra en la plataforma—, pero no
 * estar encendida (D370): vender por un marketplace no obliga a abrir la tienda web propia. Lo que sí tiene que estar
 * encendido es el canal de esa sucursal, y eso lo revisa la ingesta.
 */
final class MarketplaceWebhookController
{
    use ResolvesPublicStore;

    public function __construct(private readonly IngestMarketplaceOrder $ingest) {}

    public function handle(Request $request, string $slug, string $channel): JsonResponse
    {
        $this->resolveStore($slug, mustBeActive: false);

        $order = $this->ingest->ingest($channel, $request);

        return new JsonResponse(['status' => 'received', 'folio' => $order->folio()]);
    }
}
