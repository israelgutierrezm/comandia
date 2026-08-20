<?php

declare(strict_types=1);

namespace App\Modules\Printing\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * Se intentó abrir el cajón sin autorización (§6.3, ADR-008).
 *
 * ## Sin umbral y sin excepciones, como el retiro
 *
 * Abrir el cajón fuera de un cobro es la forma más directa de sacar dinero sin que aparezca en ningún lado: no hay
 * documento, no hay venta, no hay nada que conciliar después. Por eso §6.3 lo pone en la lista de acciones sensibles
 * junto a los descuentos y la cancelación de comandado, y por eso no hay «montos pequeños» que lo eximan — no hay monto
 * en absoluto, sólo un cajón que se abre.
 *
 * Lo que el PIN compra no es impedirlo: un gerente puede abrirlo cuando haga falta. Es que quede registrado **quién lo
 * autorizó**, con nombre, hora y motivo.
 *
 * Hereda la base del KERNEL, así que este módulo no importa nada de `Pos` ni de `Inventory` para esto y el 409 lo
 * traduce un solo sitio.
 */
final class CashDrawerRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forPrinter(string $printer): self
    {
        return new self(sprintf(
            'Abrir el cajón de «%s» necesita la autorización de un superior con su PIN. Todo cajón abierto fuera de un '
            .'cobro la necesita: es dinero al alcance sin ningún documento que lo explique.',
            $printer,
        ));
    }

    public function requiredPermission(): string
    {
        return 'pos.cash_drawer.open';
    }
}
