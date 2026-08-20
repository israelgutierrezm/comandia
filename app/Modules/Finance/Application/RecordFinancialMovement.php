<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Domain\Exceptions\FinancialMovementInvariantException;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * La ÚNICA puerta de escritura del diario financiero (ADR-004).
 *
 * ## Por qué una sola puerta
 *
 * Es la misma decisión que el kardex de la Iteración 3, y por la misma razón: si dos sitios escriben movimientos, dos
 * sitios pueden olvidarse de copiar la bandera del cajón, de poner la sucursal o de respetar la idempotencia — y el
 * diario dejaría de poder explicarse. Con una puerta, la regla se escribe una vez.
 *
 * ## Idempotente por (documento, tipo)
 *
 * La llave única de la tabla impide que un documento produzca dos movimientos del mismo tipo. Aquí se **aprovecha** en
 * lugar de comprobarse antes: se intenta insertar y, si la base rechaza por duplicado, se devuelve el que ya existía.
 *
 * Comprobar primero con un `SELECT` y luego insertar tiene una ventana entre las dos consultas, y dos oyentes del mismo
 * evento corriendo a la vez caen justo en ella. Es la corrección que la Iteración 3 hizo en `CaptureArticleCost`
 * (D212): la base es el árbitro, no la comprobación previa.
 *
 * Y esto es lo que hace **seguro re-despachar un evento** para reparar un fallo de oyente, que es el mecanismo con el
 * que D220 evitó que una confirmación mintiera.
 *
 * ## Nadie llama a esto desde otro módulo
 *
 * Lo llaman los oyentes de `Finance` cuando escuchan un evento del kernel. El POS no lo conoce: emite `PosAccountPaid`
 * y sigue. La regla 3 de §2 no admite matices aquí — es lo que hace que el diario sea auditable.
 */
final readonly class RecordFinancialMovement
{
    /**
     * Asienta un movimiento, o devuelve el que ya existía para ese documento y tipo.
     *
     * @param  numeric-string  $amount monto CON SIGNO; el sentido natural del tipo está en `naturalSign()`
     */
    public function record(
        int $branchId,
        FinancialMovementType $type,
        string $amount,
        string $sourceType,
        string $sourceUlid,
        int $actorMembershipId,
        ?int $posSessionId = null,
        ?PaymentMethod $paymentMethod = null,
        ?FinancialMovement $reverses = null,
        ?CarbonImmutable $occurredAt = null,
    ): FinancialMovement {
        $this->assertInvariants($type, $amount, $posSessionId);

        $atributos = [
            'branch_id' => $branchId,
            'type' => $type,
            'pos_session_id' => $posSessionId,
            'payment_method_id' => $paymentMethod?->id,

            // Se COPIA, no se lee después: si mañana alguien cambia la configuración del método, los cortes de ayer no
            // deben cambiar. Sin método —un fondo de caja, una diferencia de corte— la bandera la decide el tipo: lo
            // que ocurre en la caja mueve la caja.
            'affects_cash_drawer' => $paymentMethod !== null
                ? $paymentMethod->affectsCashDrawer()
                : $this->cashByNature($type),

            'amount' => Decimal::round($amount, 2),
            'source_type' => $sourceType,
            'source_ulid' => $sourceUlid,
            'actor_membership_id' => $actorMembershipId,
            'reverses_movement_id' => $reverses?->id,
            'occurred_at' => $occurredAt ?? CarbonImmutable::now(),
        ];

        try {
            // `refresh()` porque el candado de la Iteración 3 (D217) lo exige: Eloquent devuelve el atributo asignado y
            // no el almacenado, así que un DECIMAL vuelve sin sus decimales.
            return FinancialMovement::create($atributos)->refresh();
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            // Ya estaba asentado: alguien re-despachó el evento, o dos oyentes corrieron a la vez. Se devuelve el
            // existente en lugar de lanzar, porque para quien llama el resultado es el mismo — el hecho está registrado.
            return FinancialMovement::query()
                ->where('source_type', $sourceType)
                ->where('source_ulid', $sourceUlid)
                ->where('type', $type->value)
                ->sole();
        }
    }

    /**
     * Los invariantes que no delega a la base.
     *
     * Se comprueban aquí y no en un Form Request porque **nadie llega al diario por HTTP**: quien asienta es un oyente
     * de evento, y un oyente no valida formularios. Una regla que sólo viviera en la capa HTTP no protegería nada.
     */
    private function assertInvariants(FinancialMovementType $type, string $amount, ?int $posSessionId): void
    {
        // Un asiento de cero no dice nada y ensucia el diario: si no hubo dinero, no hubo hecho. La excepción es
        // deliberada y no una comodidad — una diferencia de corte de cero SÍ es información («cuadró»), y por eso el
        // corte no asienta nada cuando la diferencia es cero en lugar de asentar un cero.
        if (Decimal::round($amount, 2) === '0.00') {
            throw FinancialMovementInvariantException::zeroAmount($type);
        }

        if ($type->requiresSession() && $posSessionId === null) {
            throw FinancialMovementInvariantException::sessionRequired($type);
        }
    }

    /**
     * ¿Un movimiento sin método de pago mueve el cajón?
     *
     * El fondo de apertura, el retiro, la liquidación de propina y la diferencia de corte no tienen método —no son
     * cobros— y sin embargo mueven el efectivo de la caja. Decidirlo por el tipo es lo correcto: **son** operaciones de
     * caja por naturaleza.
     */
    private function cashByNature(FinancialMovementType $type): bool
    {
        return in_array($type, [
            FinancialMovementType::OpeningFloat,
            FinancialMovementType::Withdrawal,
            FinancialMovementType::TipSettlement,
            FinancialMovementType::CountDifference,
        ], strict: true);
    }

    /**
     * ¿La excepción es un choque con la llave de idempotencia?
     *
     * Se mira el código SQLSTATE 23000 y el nombre del índice. Comprobar sólo el 23000 haría que un choque con
     * cualquier otro único —el ULID, por improbable que sea— se tomara por un duplicado legítimo y devolviera un
     * movimiento equivocado.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'financial_movements_idempotency_unique');
    }
}
