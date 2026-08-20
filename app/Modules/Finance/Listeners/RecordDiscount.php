<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Shared\Domain\Events\PosDiscountApplied;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario lo que se dejó de cobrar.
 *
 * ## En NEGATIVO, y el diario lo exige
 *
 * Un descuento resta de lo vendido: es el importe que no se cobró. Asentarlo en positivo haría que descontar aumentara
 * la venta, que es exactamente al revés — y desde el paso 10 el diario rechaza el signo equivocado (D253) en lugar de
 * limitarse a advertirlo en un comentario.
 *
 * ## Descuento y cortesía son tipos distintos
 *
 * Aritméticamente una cortesía es un descuento del 100 %. Operativamente, «cuánto regalé» y «cuánto descontué» son dos
 * preguntas, y el reporte que §9 pide como mitigación del robo hormiga necesita poder hacerlas por separado.
 *
 * ## El actor es quien AUTORIZÓ
 *
 * No quien tocó la pantalla. Es lo que hace que el reporte agrupe por la firma que importa. Quién lo aplicó queda en la
 * bitácora y en el propio descuento, así que no se pierde.
 */
final readonly class RecordDiscount
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handle(PosDiscountApplied $event): void
    {
        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $this->journal->record(
                    branchId: $event->branchId,
                    type: $event->kind === 'courtesy'
                        ? FinancialMovementType::Courtesy
                        : FinancialMovementType::Discount,

                    // El evento lleva el importe en positivo —es lo que se dejó de cobrar— y el diario lo quiere con su
                    // sentido: restando.
                    amount: bcmul($event->amount, '-1', 2),

                    sourceType: 'App\Modules\Pos\Infrastructure\Models\PosDiscount',
                    sourceUlid: $event->discountUlid,
                    actorMembershipId: $event->authorizedByMembershipId,
                    posSessionId: $event->posSessionId,
                    occurredAt: CarbonImmutable::parse($event->appliedAt),
                );
            });
        } catch (Throwable $e) {
            // No puede tumbar la operación: el descuento ya se aplicó y la cuenta ya lo refleja (D220).
            Log::error('No se pudo asentar el descuento en el diario.', [
                'tenant_id' => $event->tenantId,
                'discount' => $event->discountUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
