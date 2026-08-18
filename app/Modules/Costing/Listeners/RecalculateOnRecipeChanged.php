<?php

declare(strict_types=1);

namespace App\Modules\Costing\Listeners;

use App\Modules\Costing\Application\RecostArticle;
use App\Modules\Costing\Events\RecipeChanged;
use App\Modules\Costing\Jobs\RecalculateDependentCosts;

/**
 * Cuando cambia una receta, recostea a su dueño y luego a lo que dependa de él.
 *
 * ## Dos pasos, y el primero es síncrono a propósito
 *
 * El dueño se recostea **en el momento**: quien acaba de guardar una receta está mirando la pantalla y
 * espera ver el costo nuevo. Dejarlo a la cola le mostraría el costo viejo durante unos segundos, y la
 * conclusión natural sería que el sistema no guardó su cambio.
 *
 * Los **dependientes** van por cola: pueden ser decenas y a nadie le urge verlos en el mismo instante.
 *
 * ## Al eliminar la receta no se recostea
 *
 * El artículo se queda sin base de cálculo, y su proyección conserva el último costo conocido — que es
 * exactamente lo que P4 define: la proyección espeja la última fila del historial inmutable, y borrar una
 * receta no borra historia. Quien pregunte por el desglose recibirá "no calculable", que es la respuesta
 * honesta.
 *
 * Los dependientes SÍ se recalculan: para ellos el componente pasó de tener costo calculable a no tenerlo,
 * y eso cambia su propia calculabilidad.
 */
final readonly class RecalculateOnRecipeChanged
{
    public function __construct(private RecostArticle $recost) {}

    public function handle(RecipeChanged $event): void
    {
        $article = $event->recipe->article;

        if ($article === null) {
            // Receta de modificador (paso 10): no participa en la cascada de artículos, porque nada la
            // consume como ingrediente.
            return;
        }

        if (! $event->deleted) {
            $this->recost->recost($article);
        }

        RecalculateDependentCosts::dispatch(
            (int) $event->recipe->tenant_id,
            $article->id,
        );
    }
}
