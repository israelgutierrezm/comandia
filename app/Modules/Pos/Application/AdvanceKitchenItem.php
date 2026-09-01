<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Events\Broadcast\KdsItemsAdvanced;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * El «bump» del tablero de cocina: avanzar una línea a preparando o listo (D350).
 *
 * ## Idempotente y sólo hacia adelante
 *
 * Marcar dos veces «preparando» no falla ni hace nada la segunda vez —dos cocineros pueden tocar el mismo plato—. Pero
 * un salto hacia atrás (un servido que vuelve a preparando) se rechaza: es una pantalla mirando un estado viejo. La
 * regla de qué transición vale es la misma del dominio (`PosOrderItemStatus::allowedNext`), no una copia.
 *
 * ## Sin PIN y sin candado de versión, a propósito
 *
 * Avanzar la preparación no es una acción sensible (no toca dinero ni composición de la cuenta), así que no pide PIN. Y
 * no exige `version`: el tablero es COMPARTIDO —varias pantallas sobre las mismas comandas— y ahí la idempotencia y el
 * «sólo hacia adelante» son el modelo correcto, no el candado optimista de un editor de una sola cuenta. Sí sube la
 * versión de la CUENTA, para que la pantalla de la cuenta note que su «Enviados» cambió.
 */
final class AdvanceKitchenItem
{
    /**
     * Avanza una línea comandada al estado dado. Devuelve la línea al día.
     */
    public function advance(PosOrderItem $item, PosOrderItemStatus $to): PosOrderItem
    {
        return DB::transaction(function () use ($item, $to): PosOrderItem {
            $item->refresh();

            // Idempotente: ya está ahí, no se hace nada (ni se difunde de más).
            if ($item->status === $to) {
                return $item;
            }

            if (! in_array($to, $item->status->allowedNext(), true)) {
                throw PosAccountException::kitchenTransitionNotAllowed(
                    (string) $item->article_name,
                    $item->status->label(),
                    $to->label(),
                );
            }

            $item->update(['status' => $to->value]);
            PosAccount::query()->whereKey($item->pos_account_id)->increment('version');
            $this->signal($item, [$item]);

            return $item->refresh();
        });
    }

    /**
     * Atajo «toda la comanda lista»: sirve las líneas vivas de la comanda (su orden, su área). Idempotente.
     */
    public function readyTicket(PosTicket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $items = PosOrderItem::query()
                ->where('pos_order_id', $ticket->pos_order_id)
                ->where('preparation_area_id', $ticket->preparation_area_id)
                ->whereIn('status', [PosOrderItemStatus::Commanded->value, PosOrderItemStatus::Preparing->value])
                ->get();

            if ($items->isEmpty()) {
                return; // ya no había nada vivo: idempotente
            }

            foreach ($items as $linea) {
                $linea->update(['status' => PosOrderItemStatus::Served->value]);
            }

            PosAccount::query()->whereKey($ticket->pos_account_id)->increment('version');
            $this->signal($items->first(), $items->all());
        });
    }

    /**
     * Difunde el cambio al canal del área (el mismo de la comanda) para que las demás pantallas se pongan al día.
     *
     * @param  list<PosOrderItem>  $items
     */
    private function signal(PosOrderItem $referencia, array $items): void
    {
        $areaId = $referencia->preparation_area_id;

        if ($areaId === null) {
            return;
        }

        $area = PreparationArea::query()->whereKey($areaId)->first();

        if ($area === null) {
            return;
        }

        $tenantUlid = Tenant::query()->whereKey($referencia->tenant_id)->value('ulid');
        $branchUlid = Branch::query()->whereKey($area->branch_id)->value('ulid');

        if ($tenantUlid === null || $branchUlid === null) {
            return;
        }

        $payload = array_map(fn (PosOrderItem $i): array => [
            'ulid' => (string) $i->ulid,
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
        ], $items);

        DB::afterCommit(fn () => KdsItemsAdvanced::dispatch(
            (string) $tenantUlid,
            (string) $branchUlid,
            (string) $area->ulid,
            $payload,
        ));
    }
}
