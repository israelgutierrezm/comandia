<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Promotions;

use App\Modules\Shared\Domain\Contracts\PromotionResolver;
use App\Modules\Shared\Domain\Promotions\PromotionOutcome;

/**
 * «Ninguna promoción», el default seguro.
 *
 * El kernel liga `PromotionResolver` a esto por omisión; `PromotionsServiceProvider` lo reemplaza por el motor real
 * cuando el módulo está presente. Si `Promotions` faltara o su binding fallara, el POS recibe una respuesta vacía y
 * cobra sin promoción — que es exactamente lo que la regla «el POS nunca se bloquea» (§6) exige. A diferencia de
 * `LiveServiceProbe`, aquí el silencio es aceptable: no aplicar una promoción no le cuesta al negocio una cuenta
 * huérfana ni un cobro perdido.
 */
final class NullPromotionResolver implements PromotionResolver
{
    public function resolveForAccount(int $branchId, string $atIso, string $branchTimezone, array $lines): PromotionOutcome
    {
        return new PromotionOutcome();
    }
}
