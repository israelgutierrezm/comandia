<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permiso del catálogo cerrado del sistema (D10).
 *
 * EXCEPCIÓN DECLARADA a la Regla A de ADR-002 (§1 del diseño): sin `tenant_id`
 * porque es un catálogo del sistema definido en un seeder versionado. El tenant
 * combina permisos en roles; no inventa permisos. No contiene dato de ningún
 * tenant, así que no hay nada que aislar.
 *
 * Wildcards deshabilitados en `config/permission.php`: un catálogo cerrado que
 * admitiera comodines dejaría de ser cerrado.
 */
final class Permission extends SpatiePermission
{
    protected $table = 'permissions';

    protected $fillable = ['name', 'guard_name', 'module', 'description'];

    /**
     * Permisos agrupados por módulo, para la pantalla de armado de roles.
     *
     * Los permisos de módulos que el tenant no tiene contratados no deben
     * mostrársele (ARQUITECTURA_MAESTRA §4.2); ese filtro lo aplica quien llama,
     * porque depende del tenant activo y este modelo es global.
     *
     * @return Collection<string, Collection<int, self>>
     */
    public static function groupedByModule(): Collection
    {
        return self::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');
    }
}
