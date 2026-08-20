<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Domain\Exceptions\BankDepositInvariantException;
use App\Modules\Finance\Infrastructure\Models\BankDeposit;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registrar un depósito bancario (§6.5, D38).
 *
 * ## Cierra el retiro
 *
 * El dinero sale de la caja con un `withdrawal` (paso 6) y entra al banco con esto. Sin la segunda mitad, un retiro de
 * diez mil pesos es una salida declarada que no llega a ningún sitio: el arqueo cuadra —el dinero salió— y nadie puede
 * decir dónde está.
 *
 * ## NO exige turno abierto, a diferencia de todo lo demás
 *
 * Y es deliberado. Un depósito lo hace quien va al banco, con el comprobante en la mano, **horas o días después** de que
 * el dinero saliera de la caja. Exigir turno abierto obligaría a capturarlo en el momento del retiro —que es cuando
 * todavía no hay comprobante— o a inventarse una sesión.
 *
 * Por eso tampoco lleva `pos_session_id`: el dinero ya salió de la caja cuando se retiró, y ese movimiento sí pertenece
 * a un turno. Éste es el otro extremo del viaje.
 *
 * ## El asiento va en la misma transacción
 *
 * Como el gasto (D274) y la liquidación de propinas: es una operación de finanzas de principio a fin.
 */
final readonly class RegisterBankDeposit
{
    public function __construct(
        private ContextHolder $context,
        private RecordFinancialMovement $journal,
        private AuditLogger $audit,
    ) {}

    public function register(
        Branch $branch,
        string $amount,
        string $bankName,
        string $reference,
        string $depositedOn,
    ): BankDeposit {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw BankDepositInvariantException::actorRequired());

        $monto = Decimal::round($amount, 2);

        return DB::transaction(function () use ($branch, $monto, $bankName, $reference, $depositedOn, $actor): BankDeposit {
            $deposito = BankDeposit::create([
                'branch_id' => $branch->id,
                'amount' => $monto,
                'bank_name' => trim($bankName),
                'reference' => trim($reference),
                'deposited_on' => $depositedOn,
                'created_by_membership_id' => $actor,
            ])->refresh();

            // En NEGATIVO: el dinero sale del negocio hacia el banco. Sin sesión, porque `deposit` no la exige — el
            // depósito ocurre fuera del turno, con el comprobante en la mano.
            $this->journal->record(
                branchId: (int) $branch->id,
                type: FinancialMovementType::Deposit,
                amount: bcmul($monto, '-1', 2),
                sourceType: BankDeposit::class,
                sourceUlid: (string) $deposito->ulid,
                actorMembershipId: $actor,
                occurredAt: CarbonImmutable::parse($depositedOn),
            );

            $this->audit->log(
                action: AuditAction::BANK_DEPOSIT_REGISTERED,
                auditable: $deposito,
                after: [
                    'amount' => $monto,
                    'bank_name' => trim($bankName),
                    'reference' => trim($reference),
                    'deposited_on' => $depositedOn,
                ],
            );

            return $deposito;
        });
    }
}
