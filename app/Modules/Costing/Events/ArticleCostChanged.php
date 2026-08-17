<?php

declare(strict_types=1);

namespace App\Modules\Costing\Events;

use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se registró una variación de costo de un artículo.
 *
 * Es el disparador del recálculo transitivo: cambiar el costo de la harina tiene que llegar al pan y
 * de ahí a la torta. Ese listener llega con el motor de costeo (paso 6); hoy el evento se emite y
 * nadie lo escucha, y eso es correcto — la alternativa sería emitirlo después, cuando ya haya código
 * escribiendo costos sin avisar.
 *
 * En la Iteración 3 lo escucha inventarios para valuar movimientos al costo vigente.
 *
 * Lleva la fila del historial y no sólo el artículo: `source_cost_id` del recálculo apunta a ella,
 * que es lo que da la cadena causal ("la torta subió porque subió el jitomate").
 */
final readonly class ArticleCostChanged
{
    use Dispatchable;

    public function __construct(
        public ArticleCost $cost,
        /** Si el costo registrado quedó como el vigente, o si es una captura retroactiva. */
        public bool $becameCurrent,
    ) {}
}
