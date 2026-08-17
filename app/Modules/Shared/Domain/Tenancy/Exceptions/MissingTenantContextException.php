<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Se consultó el contexto de tenant sin que hubiera uno resuelto.
 *
 * Esta excepción es deliberadamente ruidosa. La alternativa —devolver cero filas
 * cuando falta contexto— convierte un error de programación en un resultado vacío
 * perfectamente plausible, y ésos son los que llegan a producción sin que nadie
 * los note (ADR-002).
 *
 * Si se lanza, casi siempre es una de estas tres causas:
 *   1. Un job que no serializó su `tenant_id` ni abrió contexto con
 *      TenantContext::runFor().
 *   2. Un comando de consola o el scheduler tocando dominio sin contexto.
 *   3. Una consulta que debía ser cross-tenant y no se marcó como tal.
 *
 * Ninguna de las tres se arregla ampliando el scope: se arregla abriendo el
 * contexto donde corresponde.
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No hay contexto de tenant resuelto. Un modelo de dominio no puede consultarse '
            .'sin tenant (ADR-002). Si esto ocurre en un job, comando o prueba, abre el '
            .'contexto con TenantContext::runFor($tenantId, ...) antes de tocar el dominio.'
        );
    }
}
