<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Exceptions;

use App\Modules\Costing\Application\RecipeCycleDetector;
use RuntimeException;

/**
 * El motor de costeo encontró un ciclo mientras calculaba.
 *
 * ## Esto no es un error del usuario, es dato corrupto
 *
 * Guardar una receta con ciclo es imposible: {@see RecipeCycleDetector}
 * lo impide antes de escribir. Si el motor encuentra uno, es porque las filas llegaron por otro camino
 * —una corrección a mano en SQL, una importación, o datos anteriores a que existiera el detector—.
 *
 * Por eso es `RuntimeException` y no una excepción de dominio, y no se mapea a 422: no hay nada que el
 * usuario haya capturado mal en esta petición. La guardia existe porque la alternativa es que el motor
 * recurra sin fin, y un proceso que no termina es peor que un error.
 */
final class CostCycleDetectedException extends RuntimeException
{
    /**
     * @param  list<string>  $path
     */
    public static function whileCalculating(array $path): self
    {
        return new self(sprintf(
            'Las recetas contienen un ciclo y el costo no se puede calcular: %s. Esto no debería haber '.
            'podido guardarse; revisa la receta de esos artículos.',
            implode(' → ', $path),
        ));
    }
}
