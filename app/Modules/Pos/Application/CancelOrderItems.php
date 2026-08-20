<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Domain\Exceptions\ItemCancellationRequiresAuthorizationException;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Pos\Infrastructure\Models\PosTicketItem;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Events\PosItemsCancelled;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quitar un item de la cuenta (§6.3).
 *
 * ## Dos acciones distintas con el mismo botón
 *
 * **No comandado → se borra.** Nadie preparó nada, nadie vio el papel, no hay hecho que registrar. Pedir motivo y PIN
 * aquí sería burocracia por un plato que el mesero picó mal y corrigió en dos segundos — y entrenaría a la gente a tener
 * el PIN a mano, que es como un PIN deja de proteger.
 *
 * **Comandado → se registra.** Motivo obligatorio, PIN de un superior, comanda de cancelación al área, y hay que decir
 * qué se hizo con la comida: `waste` si ya estaba hecha, `restock` si no se tocó. Un plato que la cocina preparó y que
 * desaparece de la cuenta es la vía más común de robo en un restaurante.
 *
 * La frontera no es el monto. Es que alguien ya trabajó.
 *
 * ## El destino no lo decide el sistema
 *
 * Podría inferirse —«si el estado es `served`, es merma»— y sería adivinar: un plato marcado servido puede no haberse
 * tocado, y uno en «preparando» puede llevar media hora en la plancha. Quien está ahí lo sabe y el sistema no. Lo mismo
 * que con el precio: el sistema sugiere, el humano decide.
 */
final readonly class CancelOrderItems
{
    public function __construct(
        private ContextHolder $context,
        private PinAuthorizationService $pin,
        private CaptureOrderItems $items,

        // La bitácora se escribe AQUÍ y no en el controlador, a diferencia del resto del módulo, porque sólo este
        // servicio sabe qué se borró y qué se canceló: son dos acciones auditables distintas y la petición es una sola.
        // Es lo mismo que hacen `RegisterWaste` y `ChangeArticlePrice`.
        private AuditLogger $audit,
    ) {}

    /**
     * Cancela items de una cuenta.
     *
     * @param  list<string>  $itemUlids
     *
     * @throws ItemCancellationRequiresAuthorizationException
     */
    public function cancel(
        PosAccount $account,
        array $itemUlids,
        ?string $reason = null,
        ?string $destination = null,
        ?string $authorizationToken = null,
    ): PosAccount {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        return DB::transaction(function () use ($account, $itemUlids, $reason, $destination, $authorizationToken, $actor): PosAccount {
            $items = PosOrderItem::query()
                ->where('pos_account_id', $account->id)
                ->whereIn('ulid', $itemUlids)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($itemUlids)) {
                throw PosAccountException::itemsNotInAccount($account->displayName());
            }

            $yaCanceladas = $items->filter(fn (PosOrderItem $i): bool => $i->status === PosOrderItemStatus::Cancelled);

            if ($yaCanceladas->isNotEmpty()) {
                throw PosAccountException::itemAlreadyCancelled((string) $yaCanceladas->first()?->article_name);
            }

            $comandadas = $items->filter(fn (PosOrderItem $i): bool => $i->wasCommanded());
            $sinComandar = $items->reject(fn (PosOrderItem $i): bool => $i->wasCommanded());

            // Lo no comandado se borra, y con él sus modificadores por la cascada de la FK. No queda rastro porque no
            // ocurrió nada — es lo que §6.3 pide, y es la diferencia con todo lo demás que este sistema registra.
            if ($sinComandar->isNotEmpty()) {
                // Se audita antes de borrar, porque después ya no hay de dónde leer los nombres.
                $this->audit->log(
                    action: AuditAction::POS_ITEMS_DELETED,
                    auditable: $account,
                    before: [
                        'folio' => $account->folioNumber(),
                        'items' => $sinComandar->map(fn (PosOrderItem $i): array => [
                            'article_name' => $i->article_name,
                            'quantity' => $i->quantity,
                        ])->values()->all(),
                    ],
                );

                PosOrderItem::query()->whereIn('id', $sinComandar->pluck('id'))->delete();
            }

            if ($comandadas->isNotEmpty()) {
                $this->cancelCommanded($account, $comandadas, $reason, $destination, $authorizationToken, $actor);
            }

            return $this->items->recalculate($account);
        });
    }

    /**
     * La parte que deja rastro.
     *
     * @param  \Illuminate\Support\Collection<int, PosOrderItem>  $items
     *
     * @throws ItemCancellationRequiresAuthorizationException
     */
    private function cancelCommanded(
        PosAccount $account,
        $items,
        ?string $reason,
        ?string $destination,
        ?string $authorizationToken,
        int $actor,
    ): void {
        if ($reason === null || mb_strlen(trim($reason)) < 3) {
            throw PosAccountException::cancellationReasonRequired();
        }

        if (! in_array($destination, ['waste', 'restock'], true)) {
            throw PosAccountException::cancellationDestinationRequired();
        }

        if ($authorizationToken === null) {
            throw ItemCancellationRequiresAuthorizationException::forItem(
                (string) $items->first()?->article_name,
            );
        }

        // `consume` revalida el permiso y el estado de la membresía, y gasta la concesión: una autorización no sirve
        // para dos cancelaciones. Devuelve al AUTORIZADOR, que es quien la bitácora tiene que nombrar — el actor real de
        // una acción sensible, no quien tocó la pantalla (§6.3).
        $autorizador = $this->pin->consume($authorizationToken, 'pos.items.cancel_commanded');

        $ahora = CarbonImmutable::now();

        // El actor real de una acción sensible es quien AUTORIZÓ, y así lo pide §6.3: la bitácora tiene que nombrar al
        // gerente que puso su PIN, no al mesero que tocó la pantalla. Se registran los dos, porque saber quién pidió la
        // cancelación es la mitad del patrón que el reporte de robo hormiga busca.
        $this->audit->log(
            action: AuditAction::POS_ITEMS_CANCELLED,
            auditable: $account,
            after: [
                'folio' => $account->folioNumber(),
                'reason' => trim((string) $reason),
                'destination' => $destination,
                'requested_by_membership_id' => $actor,
                'authorized_by_membership_id' => $autorizador->id,
                'items' => $items->map(fn (PosOrderItem $i): array => [
                    'article_name' => $i->article_name,
                    'quantity' => $i->quantity,
                    'status_before' => $i->status->value,
                ])->values()->all(),
            ],
        );

        // Una comanda de cancelación POR ÁREA, con el mismo argumento que al comandar: cada área sólo tiene que enterarse
        // de lo suyo. Los items sin área no generan papel — no hay nadie a quien avisar.
        $porArea = $items->groupBy(fn (PosOrderItem $i): string => (string) ($i->preparation_area_id ?? ''));

        foreach ($porArea as $areaId => $delArea) {
            $areaId = $areaId === '' ? null : (int) $areaId;

            PosOrderItem::query()
                ->whereIn('id', $delArea->pluck('id'))
                ->update([
                    'status' => PosOrderItemStatus::Cancelled->value,
                    'cancelled_reason' => trim((string) $reason),
                    'cancelled_by_membership_id' => $autorizador->id,
                    'cancelled_at' => $ahora,
                    'cancellation_destination' => $destination,
                    'updated_at' => $ahora,
                ]);

            if ($areaId === null) {
                continue;
            }

            $this->issueCancellation($account, $areaId, $delArea, $actor, $ahora, (string) $destination);
        }
    }

    /**
     * El papel que le dice al área que lo de hace diez minutos ya no va.
     *
     * @param  \Illuminate\Support\Collection<int, PosOrderItem>  $items
     */
    private function issueCancellation(
        PosAccount $account,
        int $areaId,
        $items,
        int $actor,
        CarbonImmutable $ahora,
        string $destination,
    ): void {
        // La comanda de cancelación cuelga de la MISMA orden que la original. Es lo que permite que quien la reciba en la
        // cocina la relacione con el papel que ya tiene en la mano: «la orden 2 de la mesa 4, quita esto».
        $orderId = (int) $items->first()?->pos_order_id;

        $ticket = PosTicket::create([
            'branch_id' => $account->branch_id,
            'kind' => PosTicketKind::CommandCancellation,
            'pos_account_id' => $account->id,
            'pos_order_id' => $orderId,
            'preparation_area_id' => $areaId,
            'issued_by_membership_id' => $actor,
            'issued_at' => $ahora,
        ]);

        foreach ($items as $item) {
            PosTicketItem::create([
                'pos_ticket_id' => $ticket->id,
                'pos_order_item_id' => $item->id,
                'quantity' => $item->quantity,
            ]);
        }

        $carga = $items->map(fn (PosOrderItem $i): array => [
            'item_ulid' => (string) $i->ulid,
            'article_id' => (int) $i->article_id,
            'article_name' => (string) $i->article_name,
            'quantity' => (string) $i->quantity,
            'destination' => $destination,
        ])->values()->all();

        DB::afterCommit(function () use ($account, $areaId, $carga, $ticket, $actor, $ahora): void {
            PosItemsCancelled::dispatch(
                (int) $account->tenant_id,
                (int) $account->branch_id,
                (string) $account->ulid,
                $account->displayName(),
                $areaId,
                $carga,
                (string) $ticket->ulid,
                $actor,
                $ahora->toIso8601String(),
            );
        });
    }
}
