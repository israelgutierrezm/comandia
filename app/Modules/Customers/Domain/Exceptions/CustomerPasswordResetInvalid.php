<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Exceptions;

use DomainException;

/**
 * El enlace para crear una contraseña nueva ya no sirve (D381).
 *
 * Un solo mensaje para todas las causas —venció, ya se usó, se pidió otro, el correo del cliente cambió, el cliente se
 * inactivó o no existe—: quien tiene un enlace robado o inventado no aprende nada de la respuesta. La tienda lo traduce a
 * un 422.
 */
final class CustomerPasswordResetInvalid extends DomainException
{
    public function __construct()
    {
        parent::__construct('Este enlace ya no sirve: venció, ya se usó o se pidió otro. Pide uno nuevo.');
    }
}
