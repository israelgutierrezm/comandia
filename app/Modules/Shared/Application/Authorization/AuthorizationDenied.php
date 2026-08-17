<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Authorization;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Autorización denegada: 403.
 *
 * El mensaje NO revela qué permiso faltaba. `config/permission.php` ya desactiva
 * `display_permission_in_exception` por la misma razón: decirle al cliente el nombre
 * exacto del permiso que le falta le enumera el catálogo de capacidades del sistema.
 *
 * El permiso denegado sí se registra en la bitácora, donde lo lee quien tiene permiso
 * de auditoría.
 */
final class AuthorizationDenied extends HttpException
{
    public static function forPermission(string $permission): self
    {
        return new self(403, 'No tienes autorización para realizar esta acción.', previous: null, headers: [], code: 0);
    }

    public static function moduleNotActive(string $module): self
    {
        return new self(403, 'Esta funcionalidad no está disponible.', previous: null, headers: [], code: 0);
    }

    public static function outOfBranchScope(): self
    {
        return new self(403, 'No tienes acceso a esta sucursal.', previous: null, headers: [], code: 0);
    }

    public static function readOnlyTenant(): self
    {
        return new self(
            403,
            'La cuenta está en modo de sólo lectura. Contacta a soporte para reactivar la escritura.',
            previous: null,
            headers: [],
            code: 0,
        );
    }
}
