<?php

declare(strict_types=1);

namespace App\Modules\Costing\Listeners;

use App\Modules\Costing\Events\ArticleCostChanged;
use App\Modules\Costing\Jobs\RecalculateDependentCosts;

/**
 * Cuando cambia el costo vigente de un artículo, recostea lo que lo usa.
 *
 * ## Sólo si el costo quedó como VIGENTE
 *
 * `becameCurrent` distingue una captura normal de una **retroactiva**. Recostear por una captura que no es
 * la vigente sería trabajo inútil con resultado equivocado: el motor usaría el costo actual —no el
 * retroactivo— y escribiría un recálculo idéntico al que ya existe, ensuciando el historial de cada
 * dependiente.
 *
 * ## Síncrono, pero sólo para encolar
 *
 * El listener no calcula nada: despacha el job. Es lo correcto porque el evento se emite **dentro de la
 * transacción** que escribe el costo, y con `after_commit = true` (D65) el job no se encola hasta que la
 * transacción confirma. Si el listener costeara aquí mismo, un rollback dejaría costos calculados a partir
 * de un costo que nunca existió.
 */
final readonly class RecalculateOnCostChanged
{
    public function handle(ArticleCostChanged $event): void
    {
        if (! $event->becameCurrent) {
            return;
        }

        RecalculateDependentCosts::dispatch(
            (int) $event->cost->tenant_id,
            (int) $event->cost->article_id,
            $event->cost->id,
        );
    }
}
