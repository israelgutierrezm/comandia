<?php

declare(strict_types=1);

namespace App\Modules\Costing\Events;

use App\Modules\Costing\Infrastructure\Models\Recipe;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se guardó o se eliminó la receta de un artículo.
 *
 * Dispara el recosteo del dueño y, en cascada, de todo lo que lo use como ingrediente: cambiar la receta
 * del pan cambia el costo de la torta. Ese listener llega con el motor de costeo (paso 6); hoy el evento
 * se emite y nadie lo escucha, y eso es correcto — la alternativa es agregarlo después, cuando ya haya
 * recetas cambiando sin avisar y nadie se acuerde de por qué los costos no se actualizan.
 *
 * `deleted` distingue "cambió la composición" de "ya no hay receta": en el segundo caso el costo del
 * artículo deja de ser calculable y hay que dejar de proyectarlo, no recalcularlo a cero — un costo de
 * cero diría que producirlo es gratis.
 */
final readonly class RecipeChanged
{
    use Dispatchable;

    public function __construct(
        public Recipe $recipe,
        public bool $deleted = false,
    ) {}
}
