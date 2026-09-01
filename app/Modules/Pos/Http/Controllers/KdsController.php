<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Application\AdvanceKitchenItem;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Http\Requests\AdvanceKitchenItemRequest;
use App\Modules\Pos\Http\Resources\KdsTicketResource;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Http\Concerns\AssertsBranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * El tablero de cocina (KDS, MVP acotado — D350).
 *
 * ## Qué muestra, y de dónde sale
 *
 * Las **comandas activas** de un área: cada comanda es un `pos_ticket` (kind `command`) que esa área recibió, y sus
 * platillos son las líneas de la orden ruteadas a esa área que **todavía no terminan** —estado `comandado` o
 * `preparando`—. Una comanda desaparece del tablero cuando todas sus líneas quedan servidas o canceladas; ese estado
 * de la comanda se DERIVA de sus líneas (D350), no se guarda.
 *
 * El estado vivo vive en `pos_order_items`, no en el ticket: el ticket es la foto de lo que se mandó, la línea de la
 * orden es lo que está pasando. Por eso el tablero lee las líneas de la orden y usa el ticket sólo para el reloj
 * (`issued_at`) y el folio.
 *
 * ## Por qué vive en `Pos`
 *
 * Junta la comanda (Pos) con el área (Organization) y la mesa de la cuenta (Pos → Floor). La dirección permitida es
 * `Pos → los demás` (ADR-001), así que el que junta es el POS. Igual que el piso de venta.
 *
 * ## Aislamiento
 *
 * El área trae su sucursal; se exige `canOperateInBranch()` sobre ella, que es lo que impide ver el tablero de una
 * sucursal ajena del mismo negocio (el `tenant_id` no basta: la sucursal ajena es del mismo tenant). El cruce entre
 * negocios lo corta el global scope de tenant en cada modelo.
 */
final class KdsController
{
    use AssertsBranchScope;

    public function __construct(private readonly AdvanceKitchenItem $advancer) {}

    /**
     * Las comandas activas de un área, para el tablero.
     */
    public function tickets(Request $request, PreparationArea $preparationArea): AnonymousResourceCollection
    {
        $this->assertBranchInScope((int) $preparationArea->branch_id);

        // «Vivo» = mandado a preparar y sin terminar. Servido/cancelado sale del tablero.
        $activos = [PosOrderItemStatus::Commanded->value, PosOrderItemStatus::Preparing->value];

        $tickets = PosTicket::query()
            ->where('preparation_area_id', $preparationArea->id)
            ->where('kind', PosTicketKind::Command->value)
            // Sólo las comandas que aún tienen algo vivo en ESTA área.
            ->whereHas('order.items', fn ($q) => $q
                ->where('preparation_area_id', $preparationArea->id)
                ->whereIn('status', $activos))
            ->with([
                'account.restaurantTable',
                // Las líneas vivas de la orden en esta área; el resto de la orden (otras áreas, ya servidas) no se pinta.
                'order.items' => fn ($q) => $q
                    ->where('preparation_area_id', $preparationArea->id)
                    ->whereIn('status', $activos)
                    ->with('modifiers')
                    ->orderBy('id'),
            ])
            ->orderBy('issued_at')
            ->get();

        return KdsTicketResource::collection($tickets);
    }

    /**
     * El «bump» de una línea: marcarla preparando o lista. Idempotente; el servicio rechaza retrocesos.
     */
    public function advance(AdvanceKitchenItemRequest $request, PosOrderItem $item): JsonResponse
    {
        // El alcance por sucursal se comprueba sobre la cuenta de la línea: el tenant_id no basta (la sucursal ajena es
        // del mismo negocio).
        $item->loadMissing('account');
        $this->assertBranchInScope((int) $item->account->branch_id);

        $item = $this->advancer->advance($item, PosOrderItemStatus::from($request->string('to')->toString()));

        return response()->json(['data' => [
            'ulid' => $item->ulid,
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
        ]]);
    }

    /**
     * Atajo «toda la comanda lista»: sirve las líneas vivas de la comanda.
     */
    public function ready(PosTicket $ticket): JsonResponse
    {
        $this->assertBranchInScope((int) $ticket->branch_id);

        $this->advancer->readyTicket($ticket);

        return response()->json(['data' => ['ok' => true]]);
    }
}
