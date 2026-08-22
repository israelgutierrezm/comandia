<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se aplicó una promoción automática a una cuenta al cobrar (§6.3, D310).
 *
 * ## Dos oyentes, dos propósitos
 *
 * - `Finance` lo asienta en el diario, en negativo, como el descuento manual: una promoción es dinero que no se cobró.
 *   Pero con su propio `FinancialMovementType::Promotion` (D313), no `Discount`, para que el reporte antifraude de §9
 *   distinga lo que un humano autorizó de lo que una regla del negocio aplicó sola.
 * - `Promotions` lo anota como «registro por venta» (§6.3) en su tabla append-only.
 *
 * ## No lleva autorizador ni PIN
 *
 * A diferencia de `PosDiscountApplied`, una promoción no la autoriza nadie en el momento: la autorización ocurrió cuando
 * alguien creó la definición. Lleva el `appliedByMembershipId` —quién cobró— para el diario, y el `promotionUlid` para
 * que `Promotions` sepa cuál fue. Sí lleva sesión: la promoción se materializa al cobrar, dentro de un turno.
 */
final readonly class PosPromotionApplied implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $accountUlid,
        public ?string $orderItemUlid,

        /** El descuento de `pos_discounts` que carga el efecto monetario; es la llave de idempotencia del registro. */
        public string $discountUlid,

        public string $promotionUlid,

        /** @var numeric-string El importe que se dejó de cobrar, en positivo. */
        public string $amount,

        public int $posSessionId,
        public int $appliedByMembershipId,
        public string $appliedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
