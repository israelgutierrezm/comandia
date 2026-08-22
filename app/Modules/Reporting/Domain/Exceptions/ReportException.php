<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Exceptions;

use RuntimeException;

/**
 * Errores del motor de reportes: un reporte que no existe (404) o un parámetro fuera de la whitelist (422).
 *
 * `ReportingServiceProvider` los traduce a HTTP. El 422 es la contraparte de «lo que no está declarado no se puede pedir»
 * (ADR-006): un filtro inventado no se ignora en silencio —eso devolvería datos que el usuario cree filtrados—, se
 * rechaza.
 */
final class ReportException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notFound(string $key): self
    {
        return new self("No existe el reporte «{$key}».", 404);
    }

    public static function unknownParameters(array $keys): self
    {
        return new self('Parámetros no permitidos para este reporte: '.implode(', ', $keys).'.', 422);
    }

    public static function invalidGrouping(string $key): self
    {
        return new self("«{$key}» no es una agrupación válida de este reporte.", 422);
    }
}
