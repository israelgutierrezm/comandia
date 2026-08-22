<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Finance\Application\RecordFinancialMovement;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Shared\Domain\Events\PosPromotionApplied;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asienta en el diario lo que una PROMOCIÓN dejó de cobrar.
 *
 * Es el gemelo automático de `RecordDiscount`, con dos diferencias:
 *
 * - El tipo es `Promotion`, no `Discount` (D313): el reporte antifraude de §9 separa lo que un humano autorizó de lo
 *   que una regla aplicó sola.
 * - El actor es quien COBRÓ, no un autorizador: una promoción no la autoriza nadie en el momento (la autorización fue
 *   crear la definición). El origen del asiento es el mismo `pos_discounts` que carga el efecto, así que el diario y el
 *   descuento cuentan lo mismo una sola vez.
 *
 * En negativo, porque resta de lo vendido. Y no puede tumbar el cobro: corre tras el commit (D220).
 */
final readonly class RecordPromotion
{
    public function __construct(
        private RecordFinancialMovement $journal,
        private TenantContext $tenants,
    ) {}

    public function handle(PosPromotionApplied $event): void
    {
        try {
            $this->tenants->runFor($event->tenantId, function () use ($event): void {
                $this->journal->record(
                    branchId: $event->branchId,
                    type: FinancialMovementType::Promotion,

                    // El evento lleva el importe en positivo; el diario lo quiere restando.
                    amount: bcmul($event->amount, '-1', 2),

                    // El mismo origen que el descuento que carga el efecto: una sola verdad monetaria.
                    sourceType: 'App\Modules\Pos\Infrastructure\Models\PosDiscount',
                    sourceUlid: $event->discountUlid,
                    actorMembershipId: $event->appliedByMembershipId,
                    posSessionId: $event->posSessionId,
                    occurredAt: CarbonImmutable::parse($event->appliedAt),
                );
            });
        } catch (Throwable $e) {
            Log::error('No se pudo asentar la promoción en el diario.', [
                'tenant_id' => $event->tenantId,
                'discount' => $event->discountUlid,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
