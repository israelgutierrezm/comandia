<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Shared\Domain\Events\CustomerCreditGranted;
use App\Modules\Shared\Domain\Events\CustomerCreditRepaid;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario lo que pasa con el crédito de los clientes.
 *
 * ## Dos asientos que se ven parecidos y no lo son
 *
 * - **`credit_granted`** — se fió un consumo. **No mueve caja**: no entró dinero. Pero es un derecho de cobro, y es lo
 *   que distingue «vendí 10 000» de «cobré 8 000 y me deben 2 000».
 * - **`credit_repayment`** — el cliente pagó. **Sí mueve caja**: el efectivo entró al cajón y el arqueo tiene que
 *   conocerlo. Sin esto, un turno que recibió dos mil de fiado daría dos mil de más sin explicación.
 *
 * Confundirlos haría que fiar aumentara el efectivo esperado del corte, que es exactamente al revés.
 *
 * ## Y ninguno puede tumbar la operación
 *
 * Cuando esto corre, el saldo del cliente **ya está movido** — el cargo se hace en la transacción del cobro y el abono
 * en la suya. Un fallo al asentar se registra y no se propaga (D220); la reparación es re-despachar, porque el diario es
 * idempotente por (documento, tipo).
 */
final readonly class RecordCustomerCredit
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handleGranted(CustomerCreditGranted $event): void
    {
        $this->safely($event->tenantId, 'crédito concedido', $event->accountUlid, function () use ($event): void {
            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::CreditGranted,
                amount: $event->amount,
                sourceType: 'App\Modules\Pos\Infrastructure\Models\PosAccount',
                sourceUlid: $event->accountUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->posSessionId,
                occurredAt: CarbonImmutable::parse($event->grantedAt),
            );
        });
    }

    public function handleRepaid(CustomerCreditRepaid $event): void
    {
        $this->safely($event->tenantId, 'abono de crédito', $event->movementUlid, function () use ($event): void {
            $this->journal->record(
                branchId: $event->branchId,
                type: FinancialMovementType::CreditRepayment,
                amount: $event->amount,
                sourceType: 'App\Modules\Customers\Infrastructure\Models\CustomerCreditMovement',
                sourceUlid: $event->movementUlid,
                actorMembershipId: $event->actorMembershipId,
                posSessionId: $event->posSessionId,

                // CON método: el abono entra por una vía concreta —efectivo casi siempre— y de ahí sale que mueva el
                // cajón. Sin método, el tipo solo no lo sabría, que es la lección del gasto desde caja (D276).
                paymentMethod: PaymentMethod::query()->whereKey($event->paymentMethodId)->first(),

                occurredAt: CarbonImmutable::parse($event->repaidAt),
            );
        });
    }

    private function safely(int $tenantId, string $que, string $documento, callable $accion): void
    {
        try {
            $this->tenants->runFor($tenantId, $accion);
        } catch (Throwable $e) {
            Log::error("No se pudo asentar el {$que} en el diario.", [
                'tenant_id' => $tenantId,
                'documento' => $documento,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
