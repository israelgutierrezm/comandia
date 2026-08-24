<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Application;

use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus;
use App\Modules\Ecommerce\Infrastructure\Models\Order;
use App\Modules\Shared\Domain\Events\EcommerceOrderAccepted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Acepta un pedido pagado (Iteración 8, Tanda D parte 1, D51).
 *
 * Es el punto donde el negocio se compromete a preparar el pedido. La transición `paid → accepted` la guarda la máquina de
 * estados; se sella quién lo aceptó (o nadie, si fue automático) y cuándo. Tras el commit se emite `EcommerceOrderAccepted`
 * para que `Inventory` descuente y `Printing`/`Floor` generen las comandas — el pedido no toca esos módulos, emite el hecho
 * (ADR-004/ADR-007). El descuento vive aquí y no en el pago: un pedido rechazado nunca mueve stock.
 *
 * Idempotente por la máquina de estados: aceptar dos veces choca con la transición (un pedido ya `accepted` no puede volver
 * a `accepted`) y se rechaza, en vez de emitir el evento —y descontar— dos veces.
 */
final class AcceptOrder
{
    public function accept(Order $order, ?int $actorMembershipId): Order
    {
        $accepted = DB::transaction(function () use ($order, $actorMembershipId): Order {
            $order->transitionTo(OnlineOrderStatus::Accepted); // guarda paid → accepted
            $order->accepted_at = CarbonImmutable::now();
            $order->accepted_by_membership_id = $actorMembershipId;
            $order->save();

            return $order->refresh();
        });

        // Después del commit: los oyentes ven el pedido aceptado ya escrito (D220).
        EcommerceOrderAccepted::dispatch(
            (int) $accepted->tenant_id,
            (int) $accepted->branch_id,
            $accepted->ulid,
            $accepted->folio(),
            (int) $accepted->customer_id,
            $accepted->load('items')->items->map(fn ($i): array => [
                'article_id' => (int) $i->article_id,
                'name' => (string) $i->name,
                'quantity' => (int) $i->quantity,
                'preparation_area_id' => $i->preparation_area_id === null ? null : (int) $i->preparation_area_id,
            ])->all(),
            CarbonImmutable::now()->toIso8601String(),
        );

        return $accepted;
    }
}
