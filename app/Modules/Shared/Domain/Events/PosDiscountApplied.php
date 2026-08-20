<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se aplicó un descuento o una cortesía (§6.3).
 *
 * `Finance` lo asienta en el diario, con signo negativo: un descuento **resta** de lo vendido, porque es el importe que
 * no se cobró. Asentarlo en positivo haría que descontar aumentara la venta, que es exactamente al revés.
 *
 * ## Lleva a las DOS personas
 *
 * Quien lo aplicó y quien lo autorizó con su PIN. No es redundancia: el patrón que un reporte de robo hormiga busca es
 * «el mismo mesero pidiendo autorización veinte veces por turno», y con una sola columna esa pregunta no se puede hacer.
 */
final readonly class PosDiscountApplied implements CrossModuleEvent
{
    use Dispatchable;

    public function __construct(
        public int $tenantId,
        public int $branchId,
        public string $accountUlid,
        public string $discountUlid,

        /** `percentage`, `amount` o `courtesy`. */
        public string $kind,

        /** @var numeric-string El importe que se dejó de cobrar, en positivo. */
        public string $amount,

        public string $reason,

        /**
         * La caja en la que se aplicó.
         *
         * No es nullable: descontar exige turno abierto, igual que cobrar (D252). Un descuento es dinero que se dejó de
         * cobrar, y sin sesión el asiento del diario no tendría a qué arqueo pertenecer — el propio diario lo exige
         * para este tipo.
         */
        public int $posSessionId,

        public int $appliedByMembershipId,
        public int $authorizedByMembershipId,
        public string $appliedAt,
    ) {}

    public function tenantId(): int
    {
        return $this->tenantId;
    }
}
