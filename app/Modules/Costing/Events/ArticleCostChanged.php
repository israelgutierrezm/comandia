<?php

declare(strict_types=1);

namespace App\Modules\Costing\Events;

use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se registró una variación de costo de un artículo.
 *
 * Es el disparador del recálculo transitivo: cambiar el costo de la harina tiene que llegar al pan y
 * de ahí a la torta. Lo escucha `RecalculateOnCostChanged`, del propio módulo.
 *
 * ## No cruza módulos, y por eso se queda aquí
 *
 * Este comentario decía que «en la Iteración 3 lo escucha inventarios para valuar movimientos al costo
 * vigente», y era **falso**: no existe tal oyente. `Inventory` resuelve el costo vigente cuando registra
 * un movimiento, llamando a `ResolveArticleCost` — no reacciona a este evento. Lo descubrí al mapear los
 * oyentes reales para D231, y conviene dejarlo dicho: un comentario que afirma un acoplamiento que no
 * existe hace que alguien diseñe alrededor de él.
 *
 * Al no tener oyentes fuera de `Costing`, **no** es un evento de los que D231 manda al kernel. Si algún
 * día otro módulo lo escucha, el candado de eventos cruzados lo dirá.
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
