<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Context;

use Closure;
use RuntimeException;

/**
 * Portador del contexto del request.
 *
 * Separado de `RequestContext` porque el contexto es inmutable y el portador no: el
 * middleware lo escribe una vez, y el cambio de rol o sucursal activa reemplaza el
 * objeto completo en lugar de mutarlo.
 *
 * Registrado como singleton con alcance de request.
 */
final class ContextHolder
{
    private ?RequestContext $context = null;

    public function has(): bool
    {
        return $this->context !== null;
    }

    public function get(): RequestContext
    {
        return $this->context ?? throw new RuntimeException(
            'No hay contexto de request resuelto. Si esto ocurre en un job o comando, abre el '
            .'contexto explícitamente; si ocurre en una ruta, le falta el middleware de '
            .'resolución de tenant.'
        );
    }

    public function getOrNull(): ?RequestContext
    {
        return $this->context;
    }

    public function set(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function forget(): void
    {
        $this->context = null;
    }

    /**
     * Ejecuta el callback con otro contexto y restaura el anterior.
     *
     * Igual que `TenantContext::runFor()`, restaura en `finally`: un fallo a media
     * ejecución no puede dejar el contexto de otra persona abierto para lo que venga
     * después en el mismo proceso.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWith(RequestContext $context, Closure $callback): mixed
    {
        $previous = $this->context;

        $this->context = $context;

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }
}
