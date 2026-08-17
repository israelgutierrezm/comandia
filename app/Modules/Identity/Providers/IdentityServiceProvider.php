<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registro del módulo Identity.
 *
 * Hace dos cosas, y la segunda es la que evita un fallo silencioso grave.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Los tokens llevan tenant_id y membership_id (D69).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->bindSpatieToTenantContext();
    }

    /**
     * Ata el estado de Spatie al contexto de tenant.
     *
     * ## El problema que resuelve
     *
     * `Role` lleva global scope de tenant, como manda ADR-002. Pero el registrador
     * de permisos de Spatie construye su cache con `Permission::with('roles')` y la
     * guarda bajo **una sola llave** (`spatie.permission.cache`). Al aplicarse el
     * scope, esa carga queda filtrada por el tenant activo — y entonces la cache
     * escrita durante la petición del tenant A se reutilizaría en la del tenant B,
     * a la que le faltarían sus propios roles.
     *
     * El fallo sería **silencioso y no determinista**: dependería de qué tenant
     * calentó la cache primero, y se manifestaría como permisos denegados sin razón
     * aparente. Exactamente la clase de error que ADR-002 no puede permitirse.
     *
     * ## La solución
     *
     * La llave de cache pasa a ser **por tenant**, y se mueve junto con el contexto
     * —igual que el "team" de Spatie—. Ambas cosas se enganchan al mecanismo de
     * notificación de `TenantContext`, así que no dependen de que nadie recuerde
     * llamarlas: cambiar de tenant las cambia.
     *
     * Además coincide con lo que ARQUITECTURA_MAESTRA §5 ya pedía para la
     * configuración: cache por tenant con invalidación al escribir.
     */
    private function bindSpatieToTenantContext(): void
    {
        $baseKey = (string) config('permission.cache.key');

        $this->app->make(TenantContext::class)->onChange(
            function (?int $tenantId) use ($baseKey): void {
                /** @var PermissionRegistrar $registrar */
                $registrar = $this->app->make(PermissionRegistrar::class);

                $registrar->setPermissionsTeamId($tenantId);

                // Sin tenant no hay roles que cachear; se usa la llave base para no
                // dejar el registrador con una llave a medias.
                $registrar->cacheKey = $tenantId === null
                    ? $baseKey
                    : "{$baseKey}.tenant.{$tenantId}";

                // La colección ya cargada en memoria corresponde al tenant anterior:
                // conservarla sería servir los roles de otro negocio.
                //
                // `clearPermissionsCollection()` y NO `forgetCachedPermissions()`: el
                // segundo hace `cache->forget()`, es decir borra la cache PERSISTENTE.
                // Llamarlo en cada cambio de contexto vaciaría Redis en cada petición y
                // dejaría la cache de permisos sin ningún efecto — el rendimiento se
                // caería en silencio y nadie sabría por qué.
                $registrar->clearPermissionsCollection();
            }
        );
    }
}
