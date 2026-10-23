<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * La ÚNICA puerta de escritura del saldo de un cliente.
 *
 * Es el patrón del kardex y del diario financiero, deliberadamente: un solo sitio escribe, cada movimiento guarda el
 * saldo resultante, y la proyección se actualiza en la misma transacción. Con dos puertas, el día que una escriba mal
 * nadie sabría cuál fue.
 *
 * ## `balance_after` se calcula BAJO LOCK
 *
 * Dos cargos simultáneos del mismo cliente leerían el mismo saldo y los dos escribirían el mismo `balance_after`: el
 * estado de cuenta mostraría dos renglones con el mismo saldo resultante y el segundo cargo parecería no haber pasado.
 * El `lockForUpdate()` sobre la fila de crédito hace que el segundo espere.
 *
 * ## El signo lo impone el tipo, como en el diario
 *
 * Un cargo suma a lo que el cliente debe; un abono resta. Se **comprueba** en lugar de aplicarse en silencio, por la
 * misma razón que en el diario financiero (D253): aplicar el signo automáticamente escondería que quien llama entendió
 * mal el sentido del movimiento. El `adjustment` va en cualquier dirección, y por eso su signo natural es cero.
 *
 * ## Idempotente por (documento, tipo)
 *
 * Re-despachar el evento de una cuenta fiada no duplica su cargo. Misma llave y mismo motivo que el diario: el
 * mecanismo de reparación es volver a despachar.
 *
 * ## … y por la llave de la petición (D378)
 *
 * Un abono o un ajuste no nacen de otro documento: los registra una persona, y un doble clic los duplicaba. Llevan la
 * llave que manda quien los registra; repetirla devuelve el movimiento que ya existe —con `wasRecentlyCreated` en falso,
 * para que quien llama no vuelva a auditar ni a avisar—.
 */
final readonly class RecordCreditMovement
{
    public function record(
        Customer $customer,
        CreditMovementType $type,
        string $amount,
        int $actorMembershipId,
        ?string $sourceType = null,
        ?string $sourceUlid = null,
        ?int $posSessionId = null,
        ?string $idempotencyKey = null,
        ?string $reference = null,
        ?string $notes = null,
        ?int $authorizedByMembershipId = null,
    ): CustomerCreditMovement {
        $monto = Decimal::round($amount, 2);

        if (bccomp($monto, '0', 2) === 0) {
            throw CreditInvariantException::zeroAmount();
        }

        $natural = $type->naturalSign();

        if ($natural !== 0 && bccomp($monto, '0', 2) !== $natural) {
            throw CreditInvariantException::wrongSign($type, $monto);
        }

        return DB::transaction(function () use (
            $customer, $type, $monto, $actorMembershipId, $sourceType, $sourceUlid, $posSessionId,
            $idempotencyKey, $reference, $notes, $authorizedByMembershipId,
        ): CustomerCreditMovement {
            $credito = CustomerCredit::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first()
                ?? throw CreditInvariantException::noCreditAccount((string) $customer->name);

            // Con la fila de crédito bloqueada, la repetición de una petición se ve aquí sin carrera: la primera ya hizo
            // commit o todavía tiene el lock.
            if ($idempotencyKey !== null) {
                $previo = $this->replayOf($idempotencyKey, $customer, $type, $monto);

                if ($previo !== null) {
                    return $previo;
                }
            }

            $saldo = bcadd((string) $credito->balance, $monto, 2);

            try {
                $movimiento = CustomerCreditMovement::create([
                    'customer_id' => $customer->id,
                    'type' => $type,
                    'amount' => $monto,
                    'balance_after' => $saldo,
                    'source_type' => $sourceType,
                    'source_ulid' => $sourceUlid,
                    'pos_session_id' => $posSessionId,
                    'idempotency_key' => $idempotencyKey,
                    'reference' => $reference,
                    'notes' => $notes,
                    'created_by_membership_id' => $actorMembershipId,
                    'authorized_by_membership_id' => $authorizedByMembershipId,
                ])->refresh();
            } catch (UniqueConstraintViolationException $e) {
                // Choque con la llave de la PETICIÓN: otra petición con la misma llave la escribió a la vez, en otro
                // cliente (el de éste está bloqueado). `replayOf()` dice si es la misma petición o una llave reusada.
                if ($idempotencyKey !== null) {
                    return $this->replayOf($idempotencyKey, $customer, $type, $monto, latest: true) ?? throw $e;
                }

                // Choque con la llave de idempotencia: el movimiento ya estaba. Es el caso NORMAL de re-despachar un
                // evento, no un error — se devuelve el que ya existe y el saldo no se toca dos veces.
                if ($sourceType === null || $sourceUlid === null) {
                    throw $e;
                }

                return CustomerCreditMovement::query()
                    ->where('source_type', $sourceType)
                    ->where('source_ulid', $sourceUlid)
                    ->where('type', $type->value)
                    ->sole();
            }

            // La proyección, en la misma transacción y con el saldo que el movimiento acaba de fijar. No se recalcula
            // sumando la historia: eso sería releer todo en cada cargo, y el `balance_after` ya es la suma.
            $credito->update(['balance' => $saldo]);

            return $movimiento;
        });
    }

    /**
     * El movimiento que ya registró esta llave, si es la MISMA petición; `null` si la llave no se ha usado.
     *
     * La misma llave con otro cliente, otro tipo u otro monto no es una repetición: es una llave reusada, y devolver el
     * movimiento anterior diría que se registró algo que no se pidió.
     *
     * `$latest` lee con bloqueo, que ve el último commit y no la foto de la transacción: hace falta tras chocar con el
     * índice único, porque la fila con la que se chocó la confirmó otra transacción DESPUÉS de que ésta tomara su foto.
     * Sólo ahí: antes del choque, una lectura con bloqueo de una llave que no existe bloquearía el hueco del índice y dos
     * abonos de clientes distintos podrían esperarse mutuamente.
     *
     * @throws CreditInvariantException
     */
    public function replayOf(
        string $idempotencyKey,
        Customer $customer,
        CreditMovementType $type,
        string $monto,
        bool $latest = false,
    ): ?CustomerCreditMovement {
        $previo = CustomerCreditMovement::query()
            ->where('idempotency_key', $idempotencyKey)
            ->when($latest, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($previo === null) {
            return null;
        }

        $mismo = (int) $previo->customer_id === (int) $customer->id
            && $previo->type === $type
            && bccomp((string) $previo->amount, $monto, 2) === 0;

        if (! $mismo) {
            throw CreditInvariantException::idempotencyKeyReused();
        }

        return $previo;
    }
}
