<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Customers\Domain\Exceptions\CreditAdjustmentRequiresAuthorizationException;
use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Condonar o corregir lo que un cliente debe (D378).
 *
 * ## Por qué hacía falta
 *
 * Los movimientos de crédito son inmutables: corregir un cargo de más, o perdonar una deuda que no se va a cobrar, sólo
 * podía hacerse con un «abono» que mentía —decía que entró dinero que nunca entró— y descuadraba el arqueo del turno. El
 * tipo `adjustment` existía desde la Iteración 4 sin nadie que lo escribiera.
 *
 * ## Qué NO hace
 *
 * No toca la caja ni el diario: no entró ni salió dinero. Es una decisión sobre lo que se le va a cobrar al cliente, y
 * queda en su estado de cuenta, con su motivo, y en la bitácora con quien la autorizó.
 *
 * ## Con motivo y con PIN, siempre
 *
 * Sin umbral (ver {@see CreditAdjustmentRequiresAuthorizationException}). El PIN se gasta DESPUÉS de las validaciones:
 * una firma es de un solo uso, y gastarla en un ajuste que de todos modos se iba a rechazar obligaría a pedirla otra vez.
 *
 * ## No deja el saldo en negativo
 *
 * Lo más que se condona es lo que debe, por lo mismo que un abono no pasa de la deuda: un saldo negativo no significa nada
 * aquí. Se comprueba otra vez con la fila bloqueada, porque entre la validación y la escritura pudo entrar un abono.
 */
final readonly class AdjustCustomerCredit
{
    public const INCREASE = 'increase';

    public const DECREASE = 'decrease';

    public function __construct(
        private ContextHolder $context,
        private RecordCreditMovement $movements,
        private PinAuthorizationService $pin,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  self::INCREASE|self::DECREASE  $direction
     *
     * @throws CreditAdjustmentRequiresAuthorizationException
     */
    public function adjust(
        Customer $customer,
        string $direction,
        string $amount,
        string $reason,
        string $idempotencyKey,
        ?string $authorizationToken = null,
    ): CustomerCreditMovement {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw CreditInvariantException::noCreditAccount((string) $customer->name));

        $monto = Decimal::round($amount, 2);
        $conSigno = $direction === self::DECREASE ? bcmul($monto, '-1', 2) : $monto;
        $motivo = mb_substr(trim($reason), 0, 300);

        // Una repetición devuelve lo ya hecho sin pedir otra firma: la primera ya la gastó.
        $previo = $this->movements->replayOf($idempotencyKey, $customer, CreditMovementType::Adjustment, $conSigno);

        if ($previo !== null) {
            return $previo;
        }

        $credito = CustomerCredit::query()->where('customer_id', $customer->id)->first()
            ?? throw CreditInvariantException::noCreditAccount((string) $customer->name);

        if ($direction === self::DECREASE && bccomp($monto, (string) $credito->balance, 2) > 0) {
            throw CreditInvariantException::adjustmentBelowZero($monto, (string) $credito->balance);
        }

        if ($authorizationToken === null) {
            throw CreditAdjustmentRequiresAuthorizationException::forCustomer((string) $customer->name, $conSigno);
        }

        // Devuelve al AUTORIZADOR: el actor real de una acción sensible, que la bitácora y el estado de cuenta nombran.
        $autorizador = $this->pin->consume($authorizationToken, 'finance.customer_credit.adjust');

        return DB::transaction(function () use ($customer, $conSigno, $monto, $motivo, $idempotencyKey, $actor, $autorizador): CustomerCreditMovement {
            $movimiento = $this->movements->record(
                customer: $customer,
                type: CreditMovementType::Adjustment,
                amount: $conSigno,
                actorMembershipId: $actor,
                idempotencyKey: $idempotencyKey,
                notes: $motivo,
                authorizedByMembershipId: (int) $autorizador->id,
            );

            if (! $movimiento->wasRecentlyCreated) {
                return $movimiento;
            }

            // Un abono que entró mientras tanto pudo dejar la deuda por debajo de lo que se condonaba.
            if (bccomp((string) $movimiento->balance_after, '0', 2) < 0) {
                throw CreditInvariantException::adjustmentBelowZero($monto, bcsub((string) $movimiento->balance_after, $conSigno, 2));
            }

            $this->audit->log(
                action: AuditAction::CUSTOMER_CREDIT_ADJUSTED,
                auditable: $movimiento,
                // El saldo de ANTES sale del movimiento, que se calculó con la fila bloqueada: la lectura de arriba fue sin
                // lock y un cargo del POS pudo entrar en medio.
                before: ['balance' => bcsub((string) $movimiento->balance_after, $conSigno, 2)],
                after: [
                    'customer' => $customer->name,
                    'amount' => $conSigno,
                    'reason' => $motivo,
                    'balance_after' => $movimiento->balance_after,
                ],
                authorizedBy: $autorizador,
            );

            return $movimiento;
        });
    }
}
