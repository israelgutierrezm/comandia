<?php

declare(strict_types=1);

use App\Modules\Ecommerce\Domain\Enums\OnlineOrderStatus as S;

/**
 * La máquina de estados del pedido en línea, con los DOS caminos tras `accepted` (ADR-013): preparación
 * (`ready → completed`) y envío (`packed → shipped → completed`). El enum sólo declara qué es legal; el
 * modo de la tienda elige el camino.
 */
it('el camino de preparación (A&B) es legal', function () {
    expect(S::Accepted->canTransitionTo(S::Ready))->toBeTrue()
        ->and(S::Ready->canTransitionTo(S::Completed))->toBeTrue();
});

it('el camino de envío (retail) es legal', function () {
    expect(S::Accepted->canTransitionTo(S::Packed))->toBeTrue()
        ->and(S::Packed->canTransitionTo(S::Shipped))->toBeTrue()
        ->and(S::Shipped->canTransitionTo(S::Completed))->toBeTrue();
});

it('rechaza saltos ilegales y cruces entre caminos', function () {
    expect(S::Accepted->canTransitionTo(S::Shipped))->toBeFalse()   // hay que empacar antes
        ->and(S::Ready->canTransitionTo(S::Shipped))->toBeFalse()    // no se mezclan caminos
        ->and(S::Packed->canTransitionTo(S::Ready))->toBeFalse()
        ->and(S::Completed->canTransitionTo(S::Shipped))->toBeFalse(); // terminal
});
