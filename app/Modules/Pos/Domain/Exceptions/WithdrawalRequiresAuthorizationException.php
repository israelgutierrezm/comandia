<?php

declare(strict_types=1);

namespace App\Modules\Pos\Domain\Exceptions;

use App\Modules\Shared\Domain\Support\Exceptions\RequiresAuthorizationException;

/**
 * El retiro de caja no traía autorización (§6.3, ADR-008).
 *
 * ## Sin umbral, y ahí está la diferencia con una merma
 *
 * Una merma pide PIN sólo por encima de un monto configurable (D27, D170): un vaso roto no puede exigir la firma de un
 * gerente, o la gente deja de registrar mermas. Un retiro es otra cosa — es dinero saliendo del cajón durante el
 * servicio— y §6.3 lo pone en la lista de acciones sensibles **sin excepción de monto**. Un umbral aquí sería una puerta
 * con altura mínima.
 *
 * Hereda la base del KERNEL, así que este módulo no importa nada de `Inventory` y el 409 lo traduce un solo sitio.
 */
final class WithdrawalRequiresAuthorizationException extends RequiresAuthorizationException
{
    public static function forAmount(string $amount): self
    {
        return new self(sprintf(
            'Retirar %s de la caja necesita la autorización de un superior con su PIN. Todo retiro la necesita, sin '
            .'importar el monto: es dinero saliendo del cajón durante el servicio.',
            $amount,
        ));
    }

    public function requiredPermission(): string
    {
        return 'pos.sessions.withdraw';
    }
}
