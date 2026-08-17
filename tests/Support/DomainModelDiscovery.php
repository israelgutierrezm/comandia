<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Shared\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Descubrimiento de modelos de dominio para el test estructural de scopes de
 * tenant (ARQUITECTURA_MAESTRA §3 y §11).
 *
 * Recorre `app/` completo y no sólo `app/Modules/{X}/Infrastructure/Models`:
 * un modelo Eloquent puesto por descuido en cualquier otra carpeta seguiría
 * consultando la base de datos sin scope, y ese es exactamente el caso que este
 * mecanismo debe cazar.
 */
final class DomainModelDiscovery
{
    /**
     * Clase EXACTA del scope que acota un modelo por tenant.
     *
     * Endurecido al FQCN al cerrar el kernel de la Iteración 1: comparar por
     * nombre corto dejaba pasar un `TenantScope` casero en otro namespace, que es
     * precisamente la forma en que este candado se burlaría sin querer.
     */
    public const TENANT_SCOPE = TenantScope::class;

    /**
     * Todos los modelos Eloquent concretos declarados bajo `app/`.
     *
     * @return list<class-string<Model>>
     */
    public static function all(): array
    {
        $appPath = base_path('app');

        if (! is_dir($appPath)) {
            return [];
        }

        $models = [];

        foreach (Finder::create()->files()->in($appPath)->name('*.php') as $file) {
            $class = self::classFromPath($file->getRealPath(), $appPath);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * Modelos que NO declaran el global scope de tenant, excluyendo la lista de
     * excepciones justificadas.
     *
     * @param  list<class-string>  $allowlist
     * @return list<class-string<Model>>
     */
    public static function withoutTenantScope(array $allowlist = []): array
    {
        $offenders = [];

        foreach (self::all() as $class) {
            if (in_array($class, $allowlist, strict: true)) {
                continue;
            }

            if (! self::hasTenantScope($class)) {
                $offenders[] = $class;
            }
        }

        return $offenders;
    }

    /**
     * ¿El modelo tiene registrado el global scope de tenant?
     *
     * Instanciar el modelo dispara `bootIfNotBooted()`, con lo que quedan
     * registrados tanto los scopes añadidos en `booted()` y en los `bootTrait()`
     * como los declarados con el atributo `#[ScopedBy]`. No toca la base de datos.
     *
     * @param  class-string<Model>  $class
     */
    public static function hasTenantScope(string $class): bool
    {
        $model = new $class;

        foreach ($model->getGlobalScopes() as $key => $scope) {
            if ($key === self::TENANT_SCOPE) {
                return true;
            }

            if ($scope instanceof TenantScope) {
                return true;
            }

            if (is_string($scope) && $scope === self::TENANT_SCOPE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convierte la ruta de un archivo bajo `app/` en su FQCN según el PSR-4 del
     * proyecto (`App\` => `app/`).
     */
    private static function classFromPath(string $path, string $appPath): ?string
    {
        $relative = ltrim(str_replace([$appPath, '\\'], ['', '/'], $path), '/');

        if (! str_ends_with($relative, '.php')) {
            return null;
        }

        $relative = substr($relative, 0, -4);

        return 'App\\'.str_replace('/', '\\', $relative);
    }
}
