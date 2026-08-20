<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Customers\Domain\Enums\CreditMovementType;
use App\Modules\Customers\Domain\Exceptions\CreditInvariantException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerCredit;
use App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Contracts\CashSessionProbe;
use App\Modules\Shared\Domain\Events\CustomerCreditRepaid;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Un cliente abona a su cuenta (§6.3).
 *
 * ## Afecta cajón AL OCURRIR
 *
 * El dinero entra a la caja como efectivo, así que el arqueo tiene que conocerlo. Es la mitad que falta para que el
 * «esperado» del corte cuadre: sin abonos, un turno que recibió dos mil pesos de fiado daría dos mil de más y nadie
 * sabría de dónde salieron. Es una de las cuatro cosas que D235 puso como necesarias.
 *
 * ## Por eso exige turno abierto
 *
 * El abono pertenece a la caja que lo recibió. Sin turno sería dinero que entró a ningún cajón — el mismo argumento que
 * en los gastos (D252) y en los cobros.
 *
 * El turno lo resuelve el servidor por `CashSessionProbe`, el contrato del kernel: este módulo es dominio y no puede
 * conocer al punto de venta.
 *
 * ## No se abona de más
 *
 * Un abono mayor que la deuda dejaría el saldo en negativo, y un saldo negativo no significa nada aquí: el negocio no le
 * debe dinero al cliente por haber pagado de más, le debe un cambio en el momento. Se rechaza y quien cobra ajusta la
 * cifra.
 */
final readonly class RegisterCreditRepayment
{
    public function __construct(
        private ContextHolder $context,
        private RecordCreditMovement $movements,
        private CashSessionProbe $sessions,
        private AuditLogger $audit,
    ) {}

    public function register(
        Customer $customer,
        string $amount,
        int $branchId,
        int $paymentMethodId,
    ): CustomerCreditMovement {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw CreditInvariantException::noCreditAccount((string) $customer->name));

        $monto = Decimal::round($amount, 2);

        $sessionId = $this->sessions->openSessionIdForBranch($branchId)
            ?? throw CreditInvariantException::repaymentNeedsSession();

        $credito = CustomerCredit::query()->where('customer_id', $customer->id)->first()
            ?? throw CreditInvariantException::noCreditAccount((string) $customer->name);

        if (bccomp($monto, (string) $credito->balance, 2) > 0) {
            throw CreditInvariantException::repaymentAboveBalance($monto, (string) $credito->balance);
        }

        return DB::transaction(function () use ($customer, $monto, $actor, $sessionId, $branchId, $paymentMethodId): CustomerCreditMovement {
            $ahora = CarbonImmutable::now();

            // En NEGATIVO: un abono resta de lo que el cliente debe. El signo se comprueba en la puerta de escritura,
            // no se aplica en silencio — un abono en positivo aumentaría la deuda y nadie lo notaría hasta que él
            // reclamara.
            $movimiento = $this->movements->record(
                customer: $customer,
                type: CreditMovementType::Repayment,
                amount: bcmul($monto, '-1', 2),
                actorMembershipId: $actor,
                posSessionId: $sessionId,
            );

            $this->audit->log(
                action: AuditAction::CUSTOMER_CREDIT_REPAID,
                auditable: $movimiento,
                after: [
                    'customer' => $customer->name,
                    'amount' => $monto,
                    'balance_after' => $movimiento->balance_after,
                ],
            );

            DB::afterCommit(function () use ($customer, $movimiento, $monto, $sessionId, $branchId, $paymentMethodId, $actor, $ahora): void {
                CustomerCreditRepaid::dispatch(
                    (int) $customer->tenant_id,
                    $branchId,
                    (string) $customer->ulid,
                    (string) $movimiento->ulid,
                    $monto,
                    $sessionId,
                    $paymentMethodId,
                    $actor,
                    $ahora->toIso8601String(),
                );
            });

            return $movimiento;
        });
    }
}
