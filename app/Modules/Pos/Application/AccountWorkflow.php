<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Floor\Application\TableOccupancy;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Domain\Enums\PosAccountStatus;
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

        $quedanVivas = PosAccount::query()
            ->where('table_id', $account->table_id)
            ->open()
            ->whereKeyNot($account->id)
            ->exists();

        if ($quedanVivas) {
            return;
        }

        $table = RestaurantTable::query()->find($account->table_id);

        if ($table === null) {
            return;
        }

        // Qué significa liberar —libre o por limpiar según `floor.use_cleaning_state`, y qué pasa con la unión
        // temporal— lo decide el salón. `Pos` sólo sabe que ya no queda nada por cobrar en esa mesa.
        $this->tables->release($table, (int) $account->branch_id);
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
