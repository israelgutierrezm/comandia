<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Floor\Application\TableOccupancy;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
use App\Modules\Pos\Domain\Enums\TakeoutDeliveryStatus;
use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Abrir, pedir, cerrar y cancelar una cuenta (§6.3).
 *
 * ## La cuenta se abre SIN caja abierta
 *
 * Y es deliberado: el mesero toma la orden mucho antes de que alguien cobre, y en un turno de mañana la caja puede
 * abrirse después. §6.3 dice «sin sesión abierta no hay COBRO» — no dice «no hay cuenta». Exigir la sesión para abrir
 * dejaría al mesero esperando a que el cajero llegue.
 *
 * La sesión se ata al pagar, y ahí sí es obligatoria.
 *
 * ## El titular NO es quien abre
 *
 * Son dos columnas distintas porque son dos hechos distintos: el cajero puede abrir una cuenta de barra cuyo titular sea
 * la mesera que va a atenderla, y la propina es de ella (D233). Con una sola columna, la propina acabaría siempre a
 * nombre de quien tocó la pantalla primero.
 */
final readonly class AccountWorkflow
{
    private const DOCUMENT_TYPE = 'pos_account';

    private const SERIES = 'A';

    public function __construct(
        private DocumentNumberAllocator $folios,
        private ContextHolder $context,
        private CaptureOrderItems $items,
        private TableOccupancy $tables,
        private TakeoutNumberAllocator $takeoutNumbers,
    ) {}

    /**
     * Abre una cuenta de comer aquí, en una mesa.
     */
    public function openDineIn(RestaurantTable $table, ?int $waiterMembershipId = null): PosAccount
    {
        if (! $table->isAvailable()) {
            throw PosAccountException::tableNotAvailable((string) $table->code);
        }

        return DB::transaction(function () use ($table, $waiterMembershipId): PosAccount {
            $account = $this->create(
                branchId: (int) $table->branch_id,
                kind: 'dine_in',
                waiterMembershipId: $waiterMembershipId,
                extra: ['table_id' => $table->id],
            );

            // La mesa se ocupa al abrir la cuenta (§6.3), y lo hace `Floor`: el estado de una mesa lo dispara lo que
            // pasa con sus cuentas, pero las reglas de una mesa son del salón. Aquí había un `$table->update()` directo
            // con un comentario que decía que `Pos` era el dueño del hecho; no lo es, y el candado de fronteras lo
            // destapó. Ver `TableOccupancy`.
            $this->tables->occupy($table);

            return $account;
        });
    }

    /**
     * Abre una cuenta sin mesa: barra, o un nombre libre (§6.3).
     */
    public function openWalkIn(Branch $branch, string $label, ?int $waiterMembershipId = null): PosAccount
    {
        return DB::transaction(fn (): PosAccount => $this->create(
            branchId: (int) $branch->id,
            kind: 'dine_in',
            waiterMembershipId: $waiterMembershipId,
            extra: ['label' => $label],
        ));
    }

    /**
     * Abre un pedido PARA LLEVAR, con su número de mostrador.
     *
     * El número lo asigna `TakeoutNumberAllocator` dentro de esta misma transacción: si la cuenta no llega a crearse, el
     * número tampoco se consume. Al revés —reservarlo antes— dejaría huecos en la numeración cada vez que alguien
     * empieza un pedido y se arrepiente, y un hueco en el mostrador es un número que se grita y nadie recoge.
     */
    public function openTakeout(Branch $branch, ?int $waiterMembershipId = null): PosAccount
    {
        return DB::transaction(function () use ($branch, $waiterMembershipId): PosAccount {
            return $this->create(
                branchId: (int) $branch->id,
                kind: 'takeout',
                waiterMembershipId: $waiterMembershipId,
                extra: [
                    'takeout_number' => $this->takeoutNumbers->next($branch),

                    // Nace PENDIENTE: la cocina todavía no lo tiene. El estado sólo existe en los pedidos para llevar —
                    // en una mesa no hay nada que entregar, se sirve y ya.
                    'delivery_status' => TakeoutDeliveryStatus::Pending,
                ],
            );
        });
    }

    /**
     * Mueve el pedido por sus estados de entrega.
     *
     * ## Es una acción aparte del cobro, y a propósito
     *
     * La entrega (pending→ready→delivered) NUNCA depende del pago (D269): atar el estado de entrega al cobro haría que
     * un negocio que cobra al recoger no pudiera marcar nada como listo hasta tener el dinero — al revés de un mostrador.
     * `pos.takeout_payment_timing` sí gobierna el pago, pero en el momento de COMANDAR (ver `CommandOrder`), no aquí:
     * con `on_order` el pedido no sale a cocina hasta estar pagado; la entrega sigue su curso aparte.
     */
    public function advanceDelivery(PosAccount $account, TakeoutDeliveryStatus $target): PosAccount
    {
        if ($account->kind !== 'takeout' || $account->delivery_status === null) {
            throw PosAccountException::notATakeoutOrder($account->displayName());
        }

        if (! $account->delivery_status->canTransitionTo($target)) {
            throw PosAccountException::deliveryTransitionNotAllowed(
                $account->delivery_status->label(),
                $target->label(),
            );
        }

        $account->update(['delivery_status' => $target]);

        return $account->refresh();
    }

    /**
     * Pedir la cuenta: imprime el ticket de cierre y, si el negocio lo configuró, bloquea la captura.
     *
     * Es reversible a propósito (`bill_requested → open`): en un bar, alguien pide la cuenta y a los cinco minutos pide
     * otra cerveza. Tratarlo como irreversible obligaría a reabrir con permiso especial algo que pasa cada noche.
     */
    public function requestBill(PosAccount $account): PosAccount
    {
        $this->assertTransition($account, PosAccountStatus::BillRequested);

        return DB::transaction(function () use ($account): PosAccount {
            $account->update([
                'status' => PosAccountStatus::BillRequested,
                'bill_requested_at' => CarbonImmutable::now(),
            ]);

            // La mesa también pasa a «cuenta solicitada». §6.4 pinta ese estado en la vista de piso y hasta aquí NADA
            // lo escribía: el enum lo tenía, la pantalla lo sabía dibujar, y ninguna transición llegaba a él. Es la
            // señal de que a esa mesa le falta cobrar y no volver a atenderla.
            if ($account->restaurantTable !== null) {
                $this->tables->markBillRequested($account->restaurantTable);
            }

            return $this->items->recalculate($account);
        });
    }

    /**
     * Cierra la cuenta: el total queda fijado y se puede cobrar.
     */
    public function close(PosAccount $account): PosAccount
    {
        $this->assertTransition($account, PosAccountStatus::Closed);

        return DB::transaction(function () use ($account): PosAccount {
            // Se recalcula ANTES de fijar: cerrar con un total desactualizado sería cobrar otra cosa de lo que hay en la
            // cuenta, y es el momento en que más importa que coincidan.
            $this->items->recalculate($account);

            $account->update([
                'status' => PosAccountStatus::Closed,
                'closed_at' => CarbonImmutable::now(),
            ]);

            return $account->refresh();
        });
    }

    /**
     * Vuelve a abrir una cuenta.
     *
     * Desde `bill_requested` es rutina —el cliente pidió algo más— y desde `closed` exige el permiso propio
     * `pos.accounts.reopen`, que la ruta ya verifica. La diferencia importa: reabrir una cerrada deshace un total que
     * alguien ya vio impreso.
     */
    public function reopen(PosAccount $account): PosAccount
    {
        $this->assertTransition($account, PosAccountStatus::Open);

        return DB::transaction(function () use ($account): PosAccount {
            $account->update([
                'status' => PosAccountStatus::Open,
                'bill_requested_at' => null,
                'closed_at' => null,
            ]);

            return $this->items->recalculate($account);
        });
    }

    /**
     * Cancela una cuenta.
     *
     * ## Sólo sin pagos, y por eso no hay excepción
     *
     * Una cuenta con pagos aplicados NO se cancela: se corrige por reversa de sus pagos. Cancelarla borraría la venta y
     * dejaría los pagos apuntando a algo que el sistema dice que nunca se cobró — que es precisamente lo que un diario
     * append-only existe para que no pase.
     */
    public function cancel(PosAccount $account, string $reason): PosAccount
    {
        $this->assertTransition($account, PosAccountStatus::Cancelled);

        if (bccomp((string) $account->paid_total, '0.00', 2) !== 0) {
            throw PosAccountException::accountDoesNotAcceptItems($account->displayName(), 'pagada');
        }

        return DB::transaction(function () use ($account, $reason): PosAccount {
            $account->update([
                'status' => PosAccountStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_reason' => $reason,
            ]);

            $this->releaseTableIfEmpty($account);

            return $account->refresh();
        });
    }

    /**
     * Crea la cuenta con su folio.
     *
     * @param  array<string, mixed>  $extra
     */
    private function create(int $branchId, string $kind, ?int $waiterMembershipId, array $extra): PosAccount
    {
        $membershipId = (int) ($this->context->get()->membership?->id
            ?? throw PosAccountException::membershipRequired());

        // El folio se toma DENTRO de la transacción, que el llamador ya abrió: el allocator lo exige y falla si no la
        // hay, porque fuera de transacción el lock se libera de inmediato y dos peticiones tomarían el mismo número.
        $folio = $this->folios->next($branchId, self::DOCUMENT_TYPE, self::SERIES);

        return PosAccount::create(array_merge([
            'branch_id' => $branchId,
            'series' => self::SERIES,
            'folio' => $folio,
            'kind' => $kind,
            'status' => PosAccountStatus::Open,

            // Si no se dice quién es el titular, lo es quien abre. Es lo correcto para una barra donde el cajero atiende,
            // y sigue permitiendo que un mesero abra la cuenta de otro.
            'waiter_membership_id' => $waiterMembershipId ?? $membershipId,
            'opened_by_membership_id' => $membershipId,
            'opened_at' => CarbonImmutable::now(),
        ], $extra))->refresh();
    }

    /**
     * Cambia la cuenta de mesa, o le asigna una a una cuenta de barra.
     *
     * ## Es la operación de piso más común que no estaba
     *
     * «Nos pasamos a la mesa del fondo» ocurre en cada servicio, y hasta el paso 13 la única salida era cancelar la
     * cuenta y volver a capturar todo — que además pide PIN por cada item ya comandado. Con esto, la cuenta se mueve y
     * las dos mesas quedan como deben.
     *
     * ## El orden importa: primero se ocupa la nueva, luego se libera la vieja
     *
     * Al revés dejaría un instante con las dos mesas libres, y otro mesero podría sentar gente en la de destino. Ocupar
     * primero hace que la de destino esté tomada antes de soltar nada; si no estuviera disponible, `TableOccupancy`
     * lanza y la transacción deshace todo sin haber liberado la original.
     */
    public function moveToTable(PosAccount $account, RestaurantTable $table): PosAccount
    {
        if ((int) $account->branch_id !== (int) $table->branch_id) {
            throw PosAccountException::accountsFromDifferentBranches();
        }

        if ((int) ($account->table_id ?? 0) === (int) $table->id) {
            throw PosAccountException::alreadyAtTable((string) $table->code);
        }

        if (! $account->status->acceptsItems()) {
            throw PosAccountException::accountNotOperable($account->displayName(), $account->status->label());
        }

        return DB::transaction(function () use ($account, $table): PosAccount {
            $anterior = $account->restaurantTable;

            $this->tables->occupy($table);

            $account->update([
                'table_id' => $table->id,

                // La etiqueta libre deja de tener sentido: la cuenta ya se identifica por su mesa, y conservar las dos
                // haría que `displayName()` tuviera que elegir — que es justo lo que el invariante del paso 7 impide.
                'label' => null,
            ]);

            // Se libera la mesa que se DEJÓ, no la de la cuenta —que ya es la nueva—.
            if ($anterior !== null) {
                $this->releaseIfNoLiveAccounts((int) $anterior->id, (int) $account->branch_id);
            }

            return $account->refresh();
        });
    }

    /**
     * Libera la mesa si no queda ninguna cuenta viva en ella.
     *
     * §6.3: «la mesa se libera cuando TODAS las sub-cuentas están pagadas». No puede ser un `CHECK` ni un trigger porque
     * depende de N filas de otra tabla — es lógica de aplicación, y la prueba que importa es la de dividir en cuatro y
     * pagar tres.
     *
     * A qué estado vuelve la mesa lo decide `TableOccupancy::release()`: es una regla del salón, no del POS.
     */
    public function releaseTableIfEmpty(PosAccount $account): void
    {
        if ($account->table_id === null) {
            return;
        }

        $this->releaseIfNoLiveAccounts(
            (int) $account->table_id,
            (int) $account->branch_id,
            exceptAccountId: (int) $account->id,
        );
    }

    /**
     * Libera una mesa concreta si ya no queda nada vivo en ella.
     *
     * Recibe el id de la MESA y no una cuenta, porque al mover una cuenta de mesa hay que liberar la que se dejó — y esa
     * mesa ya no es la de la cuenta. La primera versión de `moveToTable` resolvía eso rellenando a mano el `table_id`
     * viejo en un modelo refrescado, que funcionaba y era un truco: cualquiera que leyera esa línea después tendría que
     * reconstruir por qué el modelo miente sobre su propia mesa.
     */
    public function releaseIfNoLiveAccounts(int $tableId, int $branchId, ?int $exceptAccountId = null): void
    {
        $quedanVivas = PosAccount::query()
            ->where('table_id', $tableId)
            ->open()
            ->when($exceptAccountId !== null, fn ($q) => $q->whereKeyNot($exceptAccountId))
            ->exists();

        if ($quedanVivas) {
            return;
        }

        $table = RestaurantTable::query()->find($tableId);

        if ($table === null) {
            return;
        }

        // Qué significa liberar —libre o por limpiar según `floor.use_cleaning_state`, y qué pasa con la unión
        // temporal— lo decide el salón. `Pos` sólo sabe que ya no queda nada por cobrar en esa mesa.
        $this->tables->release($table, $branchId);
    }

    /**
     * ¿La transición está permitida?
     *
     * Se consulta al enum, que es la única fuente de la máquina de estados y la que el recurso expone en `allowed_next`.
     * Repetir las reglas aquí daría dos copias que se desvían — la lección de D139.
     */
    private function assertTransition(PosAccount $account, PosAccountStatus $destino): void
    {
        if (! in_array($destino, $account->status->allowedNext(), strict: true)) {
            throw PosAccountException::accountDoesNotAcceptItems(
                $account->displayName(),
                $account->status->label(),
            );
        }
    }
}
