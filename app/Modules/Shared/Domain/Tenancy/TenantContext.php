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
 * Esta clase NO conoce el modelo Tenant a propósito: guarda un identificador. Así
 * el kernel de tenancy no depende de la capa de persistencia del módulo Tenancy, y
 * el global scope puede aplicarse sin cargar una fila de más en cada consulta.
 *
 * ## Por qué existe el mecanismo de notificación
 *
 * Cambiar de tenant no es sólo cambiar un entero: hay infraestructura que tiene
 * que reaccionar en el mismo instante. El caso concreto que lo obligó es Spatie:
 * su registrador de permisos carga la cache con `Permission::with('roles')` bajo
 * **una sola llave global**, así que un global scope de tenant en `Role` haría que
 * la cache de un tenant se guardara con los roles de otro. La solución es que la
 * llave de cache y el "team" de Spatie cambien junto con el contexto, siempre, sin
 * depender de que alguien se acuerde de llamar a dos métodos en orden.
 *
 * Los oyentes los registra cada módulo del kernel en su service provider; este
 * archivo no conoce a ninguno.
 */
final class TenantContext
{
    private ?int $tenantId = null;

    /**
     * @var list<Closure(int|null): void>
     */
    private array $listeners = [];

    /**
     * ¿Hay tenant resuelto?
     *
     * Úsalo sólo en infraestructura (middleware, resolución de contexto). El código
     * de dominio no debería preguntar: si necesita el tenant, lo pide.
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

    /**
     * Registra infraestructura que debe reaccionar al cambio de tenant.
     *
     * @param  Closure(int|null): void  $listener
     */
    public function onChange(Closure $listener): void
    {
        $this->listeners[] = $listener;

        // Se invoca de inmediato para que un oyente registrado después de que el
        // contexto ya estuviera resuelto no arranque desincronizado.
        $listener($this->tenantId);
    }

    public function set(int $tenantId): void
    {
        $this->tenantId = $tenantId;

        $this->notify();
    }

    public function forget(): void
    {
        $this->tenantId = null;

        $this->notify();
    }

    /**
     * Ejecuta el callback dentro del contexto de un tenant y restaura el anterior.
     *
     * **Único camino permitido** para tocar el dominio fuera de HTTP. Todo job
     * serializa su `tenant_id` y abre contexto con esto antes de trabajar
     * (ARQUITECTURA_MAESTRA §3, §6).
     *
     * Restaura el contexto previo incluso si el callback lanza: un job que falla a
     * media cola no puede dejar el contexto de otro tenant abierto para el
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
        $this->notify();

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
            $this->notify();
        }
    }

    /**
     * Ejecuta el callback sin ningún tenant activo.
     *
     * Para el módulo de super admin y para pruebas que verifiquen que el dominio
     * falla ruidosamente sin contexto. No es un atajo para consultas cross-tenant:
     * para eso está `withoutGlobalScopes()`, cuyo uso está restringido y vigilado
     * por test.
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
        $this->notify();

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
            $this->notify();
        }
    }

    private function notify(): void
    {
        foreach ($this->listeners as $listener) {
            $listener($this->tenantId);
        }
    }
}
