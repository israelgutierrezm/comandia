<?php

declare(strict_types=1);

namespace App\Modules\Costing\Domain\Exceptions;

use DomainException;

/**
 * La receta que se intentó guardar crearía un ciclo en el grafo de composición (D16).
 *
 * ## Por qué el mensaje lleva el camino completo
 *
 * "Se detectó un ciclo" obliga al usuario a buscarlo a mano entre decenas de recetas. «Pan → Masa →
 * Torta → Pan» le dice exactamente dónde está. La diferencia es la que hay entre un error que se puede
 * arreglar y uno que se reporta como bug.
 */
final class RecipeCycleException extends DomainException
{
    /**
     * @param  list<string>  $path  nombres de los artículos del ciclo, en orden
     */
    private function __construct(string $message, public readonly array $path)
    {
        parent::__construct($message);
    }

    public static function selfReference(string $articleName): self
    {
        return new self(
            sprintf('«%s» no puede ser ingrediente de sí mismo.', $articleName),
            [$articleName, $articleName],
        );
    }

    /**
     * @param  list<string>  $path  del componente hasta volver al artículo dueño
     */
    public static function withPath(array $path): self
    {
        return new self(
            sprintf(
                'Esta receta crearía un ciclo: %s. Un ingrediente no puede depender, ni de forma '.
                'indirecta, del artículo que lo usa — el costo no se podría calcular nunca.',
                implode(' → ', $path),
            ),
            $path,
        );
    }
}
