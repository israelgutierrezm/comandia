<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rol del tenant (D10), sobre Spatie con teams = tenant.
 *
 * Extiende el modelo de Spatie y le añade el global scope de tenant, así que
 * cumple la Regla A de ADR-002 como cualquier otra tabla de dominio. `tenant_id`
 * es NOT NULL (D68): en Comandia no existen roles globales, y el super admin vive
 * fuera de Spatie.
 *
 * ## Advertencia sobre la cache de Spatie
 *
 * El registrador de permisos de Spatie carga su cache con
 * `Permission::with('roles')`. Como este modelo lleva global scope de tenant, esa
 * consulta queda filtrada por el tenant activo — y la cache de Spatie usa **una
 * sola llave**. Sin más medidas, la cache de un tenant se guardaría con los roles
 * de otro.
 *
 * Por eso `IdentityServiceProvider` registra un oyente en `TenantContext` que
 * mueve la llave de cache **y** el team de Spatie cada vez que cambia el tenant.
 * No es una optimización: es lo que hace correcta la combinación de scope y cache.
 *
 * @property string $ulid
 * @property bool $is_system
 * @property bool $requires_two_factor
 */
final class Role extends SpatieRole
{
    use BelongsToTenant;
    use HasPublicUlid;

    protected $table = 'roles';

    /**
     * Spatie usa `$guarded = []` en su modelo, que es asignación masiva abierta y
     * está prohibida por ARQUITECTURA_MAESTRA §10.7. Se restringe aquí.
     *
     * `is_system` NO es asignable en masa: el rol Propietario se marca desde el
     * seeder versionado, y nadie debe poder promover un rol a rol de sistema desde
     * una petición.
     */
    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'requires_two_factor',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'requires_two_factor' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * El rol Propietario no es editable ni borrable (D10).
     */
    public function isProtected(): bool
    {
        return $this->is_system;
    }

    /**
     * Nombres de los permisos de ESTE rol.
     *
     * Deliberadamente no se llama `permissions()`: ése es el nombre de la relación
     * de Spatie y quería que en el código quedara visible que aquí se lee el
     * conjunto de UN rol, no la suma de los roles de una persona (D9).
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->permissions()
            ->pluck('name')
            ->map(fn (string $name): string => $name)
            ->all();
    }
}
