<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Tenancy;

use App\Modules\Shared\Domain\Tenancy\Exceptions\MissingTenantContextException;
use Closure;

/**
 * Portador del tenant activo durante una unidad de trabajo.
 *
 * Es el único lugar del sistema que sabe "en qué tenant estamos". Lo escribe el
 * middleware de resolución de contexto en HTTP, y {@see self::runFor()} fuera de
 * HTTP (jobs, comandos, scheduler, pruebas).
 *
 * Registrado como singleton: un request, un tenant (ARQUITECTURA_MAESTRA §3).
 *
 * Esta clase NO conoce el modelo Tenant a propósito: guarda un identificador.
 * Así el kernel de tenancy no depende de la capa de persistencia del módulo
 * Tenancy, y el global scope puede aplicarse sin cargar una fila de más en cada
 * consulta.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    /**
     * ¿Hay tenant resuelto?
     *
     * Úsalo sólo en infraestructura (middleware, resolución de contexto). El
     * código de dominio no debería preguntar: si necesita el tenant, lo pide.
     */
    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    /**
     * El tenant activo.
     *
     * @throws MissingTenantContextException si no hay contexto resuelto
     */
    public function id(): int
    {
        if ($this->tenantId === null) {
            throw MissingTenantContextException::make();
        }

        return $this->tenantId;
    }

    /**
     * El tenant activo, o null. Reservado a infraestructura.
     */
    public function idOrNull(): ?int
    {
        return $this->tenantId;
    }

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    /**
     * Ejecuta el callback dentro del contexto de un tenant y restaura el anterior.
     *
     * **Único camino permitido** para tocar el dominio fuera de HTTP. Todo job
     * serializa su `tenant_id` y abre contexto con esto antes de trabajar
     * (ARQUITECTURA_MAESTRA §3, §6).
     *
     * Restaura el contexto previo incluso si el callback lanza: un job que falla
     * a media cola no puede dejar el contexto de otro tenant abierto para el
     * siguiente job del mismo worker. Ése sería precisamente el escenario de fuga
     * de datos que ADR-002 existe para evitar.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(int $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;

        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    /**
     * Ejecuta el callback sin ningún tenant activo.
     *
     * Para el módulo de super admin y para pruebas que verifiquen que el dominio
     * falla ruidosamente sin contexto. No es un atajo para consultas
     * cross-tenant: para eso está `withoutTenantScope()`, cuyo uso está
     * restringido y vigilado por test.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runWithout(Closure $callback): mixed
    {
        $previous = $this->tenantId;

        $this->tenantId = null;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
