<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se intentó mover un registro de un tenant a otro.
 *
 * El `tenant_id` de una fila de dominio es inmutable por definición: cambiarlo
 * trasladaría un dato operativo —una venta, un artículo, un empleado— al negocio
 * de otro. No existe caso de uso legítimo para esto en código de dominio; la
 * migración de un tenant a otro sería una operación de super admin, deliberada y
 * fuera del dominio (ADR-002, puerta de salida).
 */
final class TenantMismatchException extends RuntimeException
{
    public static function cannotChangeTenant(string $model, ?int $from, ?int $to): self
    {
        return new self(sprintf(
            'Intento de cambiar el tenant de %s (de %s a %s). El tenant_id de una fila de '
            .'dominio es inmutable (ADR-002).',
            $model,
            $from === null ? 'null' : (string) $from,
            $to === null ? 'null' : (string) $to,
        ));
    }
}
