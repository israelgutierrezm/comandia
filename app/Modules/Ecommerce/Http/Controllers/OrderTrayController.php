<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Ecommerce\Application\AcceptOrder;
use App\Modules\Ecommerce\Application\RejectOrder;
use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Http\Resources\OrderResource;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Http\Query\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * La bandeja de aceptación de pedidos (Iteración 8, Tanda D parte 1, D51). Personal, gateada por `module:Ecommerce` y los
 * permisos `ecommerce.orders.view`/`accept`. A diferencia del checkout (público, por slug), aquí el tenant lo fija el token
 * del personal, así que el binding de ruta y el global scope operan normal.
 *
 * Aceptar un pedido lo compromete a prepararse: descuenta inventario y genera las comandas por área (por evento). El actor
 * real queda registrado (auditable). El rechazo y el avance de entrega llegan en la parte 2.
 */
final class OrderTrayController
{
    public function __construct(
        private readonly AcceptOrder $acceptOrder,
        private readonly RejectOrder $rejectOrder,
        private readonly ContextHolder $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            // La bandeja se filtra por estado: los pagados por atender, los aceptados, los listos. Sirve
            // `orders_status_index` (tenant, status).
            filters: [
                'status' => 'status',
            ],
            sortable: ['placed_at'],
            searchable: [],
            // Descendente: la bandeja se abre para atender lo que acaba de entrar.
            defaultSort: '-placed_at',
        );

        $orders = $query
            ->apply(Order::query()->with(['items', 'customer']), $request)
            ->cursorPaginate($query->perPage($request));

        return OrderResource::collection($orders);
    }

    public function accept(Order $order): JsonResponse
    {
        $membershipId = (int) $this->context->get()->requireMembership()->id;

        $accepted = $this->acceptOrder->accept($order, $membershipId);

        return new JsonResponse(['data' => new OrderResource($accepted->load('items'))]);
    }

    public function reject(Request $request, Order $order): JsonResponse
    {
        $rejected = $this->rejectOrder->reject($order, (string) $request->string('reason'));

        return new JsonResponse(['data' => new OrderResource($rejected->load('items'))]);
    }

    public function ready(Order $order): JsonResponse
    {
        return $this->advance($order, OnlineOrderStatus::Ready, 'ready_at');
    }

    public function complete(Order $order): JsonResponse
    {
        return $this->advance($order, OnlineOrderStatus::Completed, 'completed_at');
    }

    /** Avanza el pedido a un estado de entrega, sellando su hito. La máquina de estados rechaza saltos ilegales. */
    private function advance(Order $order, OnlineOrderStatus $to, string $stamp): JsonResponse
    {
        $order->transitionTo($to);
        $order->{$stamp} = CarbonImmutable::now();
        $order->save();

        return new JsonResponse(['data' => new OrderResource($order->refresh()->load('items'))]);
    }
}
