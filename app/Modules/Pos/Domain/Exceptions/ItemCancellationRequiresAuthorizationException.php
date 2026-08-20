<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * Se intentó cancelar un item YA COMANDADO sin autorización (§6.3, ADR-008).
 *
 * ## Por qué la frontera está en «comandado» y no en el monto
 *
 * Una merma pide PIN por encima de un umbral configurable (D27, D170), porque un vaso roto no puede exigir la firma de
 * un gerente. Aquí no hay umbral y no debe haberlo: lo que se protege no es el valor, es que **alguien ya trabajó**. Un
 * plato de 40 pesos que la cocina hizo y que desaparece de la cuenta es la vía más común de robo en un restaurante, y es
 * la razón por la que §6.3 lo pone en la lista de acciones sensibles.
 *
 * Cancelar un item que nadie preparó, en cambio, es **borrarlo**: no ocurrió nada, no hay rastro que dejar y pedir PIN
 * ahí sólo entrenaría a la gente a tener el PIN a mano — que es como un PIN deja de proteger.
 */
final class ItemCancellationRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forItem(string $articleName): self
    {
        return new self(sprintf(
            'Cancelar «%s» necesita la autorización de un superior con su PIN: ya salió comandado y alguien se puso a '
            .'prepararlo.',
            $articleName,
        ));
    }

    public function requiredPermission(): string
    {
        return 'pos.items.cancel_commanded';
    }
}
