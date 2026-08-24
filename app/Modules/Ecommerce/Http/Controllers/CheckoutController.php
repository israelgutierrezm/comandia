<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Ecommerce\Application\PaymentProcessor;
use App\Modules\Ecommerce\Application\PlaceOrder;
use App\Modules\Ecommerce\Http\Concerns\ResolvesPublicStore;
use App\Modules\Ecommerce\Http\Requests\CheckoutRequest;
use App\Modules\Ecommerce\Http\Resources\OrderResource;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checkout y pedidos del cliente (Iteración 8, Tanda C parte 2), sin autenticación de personal.
 *
 * Comprar **exige cliente registrado** (D333): el checkout requiere una sesión de cliente. El slug resuelve el negocio y
 * fija el contexto; el pedido se folía por sucursal y nace `pending_payment` (el pago es la parte 3). Los pedidos que
 * lista/consulta son SÓLO los del cliente autenticado.
 */
final class CheckoutController
{
    use ResolvesPublicStore;

    public function __construct(
        private readonly PlaceOrder $placeOrder,
        private readonly PaymentProcessor $payments,
    ) {}

    /** Zonas de envío activas, para elegir entrega. Público (sin login): el cliente las ve antes de entrar. */
    public function shippingZones(string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);

        $zones = ShippingZone::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (ShippingZone $z): array => ['ulid' => $z->ulid, 'name' => $z->name, 'cost' => $z->cost]);

        return new JsonResponse(['data' => $zones]);
    }

    public function checkout(CheckoutRequest $request, string $slug): JsonResponse
    {
        $store = $this->resolveStore($slug);
        $customer = $this->requireCustomer();

        // Antes de tocar nada: si el negocio no tiene con qué cobrar, 422 aquí —no después de crear el pedido y vaciar el
        // carrito—.
        $this->payments->ensureAvailable();

        $order = $this->placeOrder->place($store, $customer, [
            'type' => (string) $request->string('delivery_type'),
            'zone_ulid' => $request->input('zone_ulid'),
            'address' => $request->input('address'),
            'notes' => $request->input('notes'),
            'coupon_code' => $request->input('coupon_code'),
        ]);

        // Se inicia el cobro con la pasarela activa; el cliente será enviado a `payment_url` para pagar.
        $intent = $this->payments->initiate($order);

        return new JsonResponse([
            'data' => new OrderResource($order->load('items')),
            'payment_url' => $intent->redirectUrl,
        ], 201);
    }

    /**
     * @return AnonymousResourceCollection<\Illuminate\Database\Eloquent\Collection<int, Order>>
     */
    public function myOrders(string $slug): AnonymousResourceCollection
    {
        $this->resolveStore($slug);
        $customer = $this->requireCustomer();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->latest('placed_at')
            ->limit(50)
            ->get();

        return OrderResource::collection($orders);
    }

    public function showOrder(string $slug, string $order): JsonResponse
    {
        // No se usa binding de ruta: el global scope del pedido necesita el contexto, que se fija AQUÍ al resolver el slug.
        $this->resolveStore($slug);
        $customer = $this->requireCustomer();

        $found = Order::query()->where('ulid', $order)->first();

        // Un pedido es de su cliente: nadie más lo ve. Uno ajeno o inexistente responde 404 (el tenant scope ya acota; esto
        // acota además por cliente).
        if ($found === null || (int) $found->customer_id !== (int) $customer->id) {
            throw new HttpException(404, 'Pedido no encontrado.');
        }

        return new JsonResponse(['data' => new OrderResource($found->load('items'))]);
    }

    /**
     * Página de «pago simulado» de la pasarela de prueba (Iteración 8, Tanda C parte 3a). Sólo para desarrollo/demo: es a
     * donde manda el checkout con la pasarela `fake`, y desde aquí se dispara el webhook aprobado. Mercado Pago/Stripe
     * reales mandan a SU propia página (parte 3b).
     */
    public function fakePay(string $slug, string $order): \Illuminate\Contracts\View\View
    {
        $store = $this->resolveStore($slug);

        $found = Order::query()->where('ulid', $order)->firstOrFail();

        return view('store.fake-pay', [
            'slug' => $slug,
            'order' => ['ulid' => $found->ulid, 'folio' => $found->folio(), 'total' => $found->total],
        ]);
    }

    private function requireCustomer(): Customer
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            // Comprar exige cuenta (D333): sin sesión de cliente, 401.
            throw new HttpException(401, 'Inicia sesión para completar tu pedido.');
        }

        return $customer;
    }
}
