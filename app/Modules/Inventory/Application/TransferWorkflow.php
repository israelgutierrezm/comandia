<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Exceptions\TransferInvariantException;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use App\Modules\Inventory\Infrastructure\Models\TransferLine;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La máquina de estados de una transferencia y los movimientos que produce (D25, §6.2).
 *
 * ## Dónde vive la mercancía mientras viaja
 *
 * En un almacén de **tránsito**, uno por negocio, que sólo escriben las transferencias. Al enviar: origen −100,
 * tránsito +100. Al recibir: tránsito −95, destino +95. El residuo de 5 que quedó en tránsito se convierte en merma
 * ahí, con el motivo del sistema «Diferencia en tránsito», y tránsito vuelve a cero.
 *
 * Sin ese almacén, el origen bajaría 100, el destino subiría 95 y **ningún movimiento explicaría los 5 que
 * faltan**: la pérdida quedaría sólo en el documento y no aparecería en el reporte de mermas, que D168 definió como
 * un filtro sobre el kardex. Así, cada kardex dice la verdad literal y nada desaparece.
 *
 * ## Cuatro movimientos por transferencia completa, no dos
 *
 * Y todos por la única puerta de entrada al kardex (`RecordStockMovement`), con llave de idempotencia por línea y
 * por paso. Reintentar un envío interrumpido no duplica nada.
 *
 * ## Los pasos omitibles
 *
 * `authorized` y `preparing` se activan por configuración del negocio, apagados por omisión. La máquina completa
 * vive en `TransferStatus`; lo que la configuración decide es qué pasos son **obligatorios antes de enviar**, no qué
 * transiciones existen. Es una separación deliberada: un enum que dependiera de la configuración dejaría de ser una
 * declaración para volverse una regla con estado.
 */
final class TransferWorkflow
{
    /** Tipo de documento para la secuencia de folios (§7). */
    private const DOCUMENT_TYPE = 'transfer';

    private const SERIES = 'TR';

    public function __construct(
        private readonly RecordStockMovement $movements,
        private readonly ResolveTransferInfrastructure $infrastructure,
        private readonly DocumentNumberAllocator $folios,
        private readonly Settings $settings,
        private readonly ContextHolder $context,
    ) {}

    /**
     * Solicita una transferencia. Todavía no mueve nada.
     *
     * @param  list<array{article: Article, lot: ArticleLot|null, quantity: numeric-string}>  $lines
     *
     * @throws TransferInvariantException
     */
    public function request(
        Warehouse $origin,
        Warehouse $destination,
        array $lines,
        ?string $notes = null,
    ): Transfer {
        $this->assertOperable($origin);
        $this->assertOperable($destination);

        $folioBranchId = $this->resolveFolioBranch($origin, $destination);
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use ($origin, $destination, $lines, $notes, $folioBranchId, $membershipId): Transfer {
            // El folio se toma DENTRO de la transacción: el allocator lo exige y falla si no la hay, porque fuera
            // de transacción el lock se libera de inmediato y dos peticiones tomarían el mismo número (§7).
            $folio = $this->folios->next($folioBranchId, self::DOCUMENT_TYPE, self::SERIES);

            $transfer = Transfer::create([
                'origin_warehouse_id' => $origin->id,
                'destination_warehouse_id' => $destination->id,
                'status' => TransferStatus::Requested,
                'folio_branch_id' => $folioBranchId,
                'series' => self::SERIES,
                'folio' => $folio,
                'requested_by_membership_id' => $membershipId,
                'requested_at' => CarbonImmutable::now(),
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                TransferLine::create([
                    'transfer_id' => $transfer->id,
                    'article_id' => $line['article']->id,
                    'lot_id' => $line['lot']?->id,
                    'requested_quantity' => $line['quantity'],
                ]);
            }

            return $transfer->refresh();
        });
    }

    /**
     * Autoriza. Sólo existe si el negocio lo pidió.
     *
     * @throws TransferInvariantException
     */
    public function authorize(Transfer $transfer): Transfer
    {
        if (! $this->settings->get('inventory.transfers_require_authorization')) {
            throw TransferInvariantException::stepNotEnabled('autorización');
        }

        return $this->stamp($transfer, TransferStatus::Authorized, 'authorized');
    }

    /**
     * Marca como preparada. Sólo existe si el negocio lo pidió.
     *
     * @throws TransferInvariantException
     */
    public function prepare(Transfer $transfer): Transfer
    {
        if (! $this->settings->get('inventory.transfers_require_preparation')) {
            throw TransferInvariantException::stepNotEnabled('preparación');
        }

        return $this->stamp($transfer, TransferStatus::Preparing, 'prepared');
    }

    /**
     * Envía: la mercancía sale del origen y entra a tránsito.
     *
     * Las cantidades enviadas las declara quien surte, y pueden ser menores que las pedidas —«no había»— o cero para
     * una línea entera. Lo que NO pueden es superar lo pedido: para mandar más, se pide más. Si no, la cantidad
     * solicitada dejaría de servir para saber después si se pidió poco o se surtió poco, que es la razón por la que
     * la línea guarda tres cantidades.
     *
     * @param  array<int, numeric-string>  $shipped  cantidad por id de línea
     *
     * @throws TransferInvariantException
     */
    public function ship(Transfer $transfer, array $shipped): Transfer
    {
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use ($transfer, $shipped, $membershipId): Transfer {
            $locked = $this->lock($transfer);

            $this->assertCanTransition($locked, TransferStatus::Shipped);
            $this->assertRequiredStepsDone($locked);

            $transit = $this->infrastructure->transitWarehouse();
            $lines = $locked->lines()->with(['article', 'lot'])->get();

            $anyShipped = false;

            foreach ($lines as $line) {
                $quantity = $shipped[$line->id] ?? '0';

                if (bccomp($quantity, $line->requested_quantity, 4) === 1) {
                    throw TransferInvariantException::shippedMoreThanRequested($line->article->name);
                }

                $line->update(['shipped_quantity' => $quantity]);

                if (bccomp($quantity, '0', 4) !== 1) {
                    continue;
                }

                $anyShipped = true;

                // Los dos movimientos del envío. La salida del origen NO usa FEFO: el lote lo eligió quien surtió
                // y viene en la línea, porque la caducidad viaja con la mercancía física — dejar que FEFO
                // reeligiera aquí haría que el destino recibiera un lote distinto del que va en el camión.
                $this->move($locked, $line, $locked->originWarehouse, StockMovementKind::TransferOut, $quantity, 'ship:out');
                $this->move($locked, $line, $transit, StockMovementKind::TransferIn, $quantity, 'ship:in');
            }

            if (! $anyShipped) {
                throw TransferInvariantException::nothingShipped();
            }

            $locked->update([
                'status' => TransferStatus::Shipped,
                'shipped_by_membership_id' => $membershipId,
                'shipped_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        });
    }

    /**
     * Recibe: la mercancía sale de tránsito y entra al destino. Lo que no llegó se merma en tránsito.
     *
     * @param  array<int, numeric-string>  $received  cantidad por id de línea
     *
     * @throws TransferInvariantException
     */
    public function receive(Transfer $transfer, array $received): Transfer
    {
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use ($transfer, $received, $membershipId): Transfer {
            $locked = $this->lock($transfer);

            // Se comprueba contra `Received`, y sirve para las dos: la transición a `ReceivedWithDifferences`
            // está permitida desde el mismo estado, así que validar una valida la otra. Cuál de las dos queda se
            // decide abajo, con las cantidades ya escritas.
            $this->assertCanTransition($locked, TransferStatus::Received);

            $transit = $this->infrastructure->transitWarehouse();
            $lines = $locked->lines()->shipped()->with(['article', 'lot'])->get();

            $withDifferences = false;

            foreach ($lines as $line) {
                $quantity = $received[$line->id] ?? '0';

                /** @var numeric-string $shippedQuantity */
                $shippedQuantity = $line->shipped_quantity;

                if (bccomp($quantity, $shippedQuantity, 4) === 1) {
                    throw TransferInvariantException::receivedMoreThanShipped($line->article->name);
                }

                $line->update(['received_quantity' => $quantity]);

                if (bccomp($quantity, '0', 4) === 1) {
                    $this->move($locked, $line, $transit, StockMovementKind::TransferOut, $quantity, 'receive:out');
                    $this->move($locked, $line, $locked->destinationWarehouse, StockMovementKind::TransferIn, $quantity, 'receive:in');
                }

                $missing = bcsub($shippedQuantity, $quantity, 4);

                if (bccomp($missing, '0', 4) !== 1) {
                    continue;
                }

                $withDifferences = true;

                // La merma va en TRÁNSITO, no en el origen como proponía el diseño. Dos razones, y la segunda es
                // la que decide:
                //
                //   1. Es donde se perdió: salió del origen y no llegó al destino.
                //   2. En el origen sería un DOBLE CARGO. El origen ya bajó las 100 que subieron al camión;
                //      restarle otras 5 dejaría el inventario 105 abajo cuando sólo se perdieron 5.
                //
                // El almacén de la merma no es lo que atribuye responsabilidad: eso lo dice la transferencia, que
                // lleva origen, destino y quién firmó cada paso.
                $this->wasteInTransit($locked, $line, $transit, $missing);
            }

            $locked->update([
                'status' => $withDifferences
                    ? TransferStatus::ReceivedWithDifferences
                    : TransferStatus::Received,
                'received_by_membership_id' => $membershipId,
                'received_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        });
    }

    /**
     * Cancela. Sólo antes de enviar: después, la mercancía está en un camión.
     *
     * @throws TransferInvariantException
     */
    public function cancel(Transfer $transfer): Transfer
    {
        return DB::transaction(function () use ($transfer): Transfer {
            $locked = $this->lock($transfer);

            if ($locked->status->hasShipped()) {
                throw TransferInvariantException::alreadyShipped();
            }

            $this->assertCanTransition($locked, TransferStatus::Cancelled);

            $locked->update(['status' => TransferStatus::Cancelled]);

            return $locked;
        });
    }

    /**
     * Un paso que sólo pone sello: no mueve mercancía.
     *
     * @throws TransferInvariantException
     */
    private function stamp(Transfer $transfer, TransferStatus $to, string $step): Transfer
    {
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use ($transfer, $to, $step, $membershipId): Transfer {
            $locked = $this->lock($transfer);

            $this->assertCanTransition($locked, $to);

            if ($to === TransferStatus::Preparing) {
                // Preparar después de autorizar, cuando las dos están activas: preparar mercancía que nadie
                // autorizó a mover es trabajo que se puede tirar.
                $this->assertAuthorizationDone($locked);
            }

            $locked->update([
                'status' => $to,
                "{$step}_by_membership_id" => $membershipId,
                "{$step}_at" => CarbonImmutable::now(),
            ]);

            return $locked;
        });
    }

    /**
     * @throws TransferInvariantException
     */
    private function assertCanTransition(Transfer $transfer, TransferStatus $to): void
    {
        if (! $transfer->status->canTransitionTo($to)) {
            throw TransferInvariantException::cannotTransition($transfer->status, $to);
        }
    }

    /**
     * Los pasos que el negocio declaró obligatorios antes de enviar.
     *
     * Se comprueban por el SELLO y no por el estado, y la diferencia importa: con las dos activas, autorizar deja
     * la transferencia en `authorized` y preparar la deja en `preparing`, así que al enviar el estado ya no dice
     * nada de la autorización. El sello sí.
     *
     * @throws TransferInvariantException
     */
    private function assertRequiredStepsDone(Transfer $transfer): void
    {
        $this->assertAuthorizationDone($transfer);

        if (
            $this->settings->get('inventory.transfers_require_preparation')
            && $transfer->prepared_at === null
        ) {
            throw TransferInvariantException::requiresPreparationFirst();
        }
    }

    /**
     * @throws TransferInvariantException
     */
    private function assertAuthorizationDone(Transfer $transfer): void
    {
        if (
            $this->settings->get('inventory.transfers_require_authorization')
            && $transfer->authorized_at === null
        ) {
            throw TransferInvariantException::requiresAuthorizationFirst();
        }
    }

    /**
     * La sucursal de la que sale el folio: la del origen, o la del destino si el origen es central (§7).
     *
     * @throws TransferInvariantException
     */
    private function resolveFolioBranch(Warehouse $origin, Warehouse $destination): int
    {
        $branchId = $origin->branch_id ?? $destination->branch_id;

        if ($branchId === null) {
            throw TransferInvariantException::centralToCentralNeedsBranch();
        }

        return $branchId;
    }

    /**
     * @throws TransferInvariantException
     */
    private function assertOperable(Warehouse $warehouse): void
    {
        if ($warehouse->kind === WarehouseKind::Transit) {
            throw TransferInvariantException::transitWarehouseIsNotOperable();
        }
    }

    private function requireMembership(): int
    {
        $membershipId = $this->context->getOrNull()?->membership?->id;

        if ($membershipId === null) {
            // A diferencia de un movimiento de kardex, que un job puede registrar sin actor, cada paso de una
            // transferencia lo da una persona: el documento existe para decir quién.
            throw new \LogicException('Una transferencia exige una membresía en contexto.');
        }

        return $membershipId;
    }

    private function lock(Transfer $transfer): Transfer
    {
        return Transfer::query()->lockForUpdate()->whereKey($transfer->id)->sole();
    }

    /**
     * Un movimiento de la transferencia, por la única puerta de entrada al kardex.
     *
     * @param  numeric-string  $quantity
     */
    private function move(
        Transfer $transfer,
        TransferLine $line,
        Warehouse $warehouse,
        StockMovementKind $kind,
        string $quantity,
        string $step,
    ): void {
        $this->movements->record(
            warehouse: $warehouse,
            article: $line->article,
            kind: $kind,
            quantity: $quantity,
            lot: $line->lot,
            source: $transfer,

            // Por línea Y por paso. Sin el paso, la salida de tránsito al recibir compartiría llave con la entrada
            // a tránsito al enviar, y la segunda se tomaría por un reintento de la primera: la recepción no
            // movería nada y la mercancía se quedaría en tránsito para siempre.
            idempotencyKey: "transfer:{$transfer->id}:line:{$line->id}:{$step}",
            notes: sprintf('Transferencia %s', $transfer->folioNumber()),
        );
    }

    /**
     * La merma de lo que salió y no llegó.
     *
     * No pasa por `RegisterWaste`, y es deliberado: ese servicio existe para las mermas que una persona declara —con
     * su umbral y su PIN— y esta la declara el sistema al cuadrar dos cantidades. Pedirle autorización a alguien
     * por una diferencia que el propio documento ya prueba dejaría la mercancía en tránsito hasta que apareciera un
     * gerente, y tránsito no es un sitio donde algo pueda quedarse.
     *
     * @param  numeric-string  $quantity
     */
    private function wasteInTransit(
        Transfer $transfer,
        TransferLine $line,
        Warehouse $transit,
        string $quantity,
    ): void {
        $movement = $this->movements->record(
            warehouse: $transit,
            article: $line->article,
            kind: StockMovementKind::Waste,
            quantity: $quantity,
            lot: $line->lot,
            source: $transfer,
            idempotencyKey: "transfer:{$transfer->id}:line:{$line->id}:transit-waste",
            notes: sprintf('Diferencia en tránsito, transferencia %s', $transfer->folioNumber()),
        );

        // El motivo se escribe después, por el query builder, exactamente como en `RegisterWaste::stampReason()` y
        // por lo mismo: `stock_movements` es inmutable y el trait bloquea `update()`. Es la escritura inicial
        // partida en dos, dentro de la misma transacción, porque el registro del kardex no sabe de mermas.
        StockMovement::query()
            ->whereKey($movement->id)
            ->toBase()
            ->update(['waste_reason_id' => $this->infrastructure->transitDifferenceReason()->id]);
    }
}
