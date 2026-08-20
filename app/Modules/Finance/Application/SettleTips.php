<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Domain\Exceptions\TipSettlementInvariantException;
use App\Modules\Finance\Infrastructure\Models\TipSettlement;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Contracts\CashSessionProbe;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Entregarle a alguien la propina que ganó (§6.6, D39).
 *
 * ## Sin esto, el arqueo da corto y nadie sabe por qué
 *
 * Las propinas entran a la caja con el resto del cobro. Cuando el cajero se las entrega al mesero al cerrar, si esa
 * salida no está registrada el arqueo da corto por una cantidad que ningún movimiento explica — y como pasa todas las
 * noches, la diferencia deja de mirarse. Es la cuarta cosa que D235 identificó como necesaria y la que menos se ve venir.
 *
 * ## Afecta cajón, y por eso exige turno abierto
 *
 * La propina se paga en efectivo del cajón. El asiento va en negativo —sale dinero— y con el tipo `tip_settlement`, que
 * el diario ya declara como movimiento de caja por naturaleza.
 *
 * ## El asiento va DENTRO de la transacción
 *
 * Como el gasto (D274) y por lo mismo: es una operación de finanzas de principio a fin, no un efecto de algo que ocurrió
 * en el punto de venta. Una liquidación sin su asiento sería dinero que salió del cajón y que el corte no conoce.
 *
 * ## No se liquida más de lo que se debe
 *
 * Y el disponible se recalcula **dentro** de la transacción, no se acepta del cliente: entre que la pantalla lo mostró y
 * el cajero apretó el botón, otra terminal pudo liquidar lo mismo.
 */
final readonly class SettleTips
{
    public function __construct(
        private ContextHolder $context,
        private CalculateAvailableTips $tips,
        private RecordFinancialMovement $journal,
        private CashSessionProbe $sessions,
        private AuditLogger $audit,
    ) {}

    public function settle(TenantMembership $member, string $amount, Branch $branch): TipSettlement
    {
        $actor = (int) ($this->context->get()->membership?->id
            ?? throw TipSettlementInvariantException::actorRequired());

        $monto = Decimal::round($amount, 2);

        if (bccomp($monto, '0', 2) <= 0) {
            throw TipSettlementInvariantException::notPositive();
        }

        $sessionId = $this->sessions->openSessionIdForBranch((int) $branch->id)
            ?? throw TipSettlementInvariantException::needsSession();

        return DB::transaction(function () use ($member, $monto, $branch, $actor, $sessionId): TipSettlement {
            // Se recalcula AQUÍ dentro. Entre que la pantalla mostró el disponible y el cajero apretó el botón, otra
            // terminal pudo liquidar lo mismo — y sin esta comprobación se pagaría dos veces.
            $disponible = $this->tips->forMembership((int) $member->id);

            if (bccomp($monto, $disponible, 2) > 0) {
                throw TipSettlementInvariantException::aboveAvailable($monto, $disponible);
            }

            $ahora = CarbonImmutable::now();

            $liquidacion = TipSettlement::create([
                'branch_id' => $branch->id,
                'pos_session_id' => $sessionId,
                'membership_id' => $member->id,
                'amount' => $monto,
                'paid_by_membership_id' => $actor,
            ])->refresh();

            // En NEGATIVO: el dinero sale del cajón. El diario lo rechaza con el signo equivocado desde el paso 10
            // (D253), y `tip_settlement` está entre los tipos que mueven caja por naturaleza aunque no lleven método.
            //
            // El actor es a QUIEN se le pagó y no quien pagó: es lo que permite que el disponible se calcule del
            // diario sumando por persona, igual que los asientos de propina del paso 10.
            $this->journal->record(
                branchId: (int) $branch->id,
                type: FinancialMovementType::TipSettlement,
                amount: bcmul($monto, '-1', 2),
                sourceType: TipSettlement::class,
                sourceUlid: (string) $liquidacion->ulid,
                actorMembershipId: (int) $member->id,
                posSessionId: $sessionId,
                occurredAt: $ahora,
            );

            $this->audit->log(
                action: AuditAction::TIPS_SETTLED,
                auditable: $liquidacion,
                after: [
                    'amount' => $monto,
                    'paid_to_membership_id' => $member->id,
                    'paid_by_membership_id' => $actor,
                ],
            );

            return $liquidacion;
        });
    }
}
