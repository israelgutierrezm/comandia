<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activación comercial de un módulo activable (D3).
 *
 * Sólo se materializan `DigitalMenus` y `Ecommerce`: los módulos del núcleo no
 * tienen fila, porque preguntar si el POS está activo no tiene sentido y una fila
 * que siempre vale `true` es una invitación a apagarla por error.
 *
 * El nombre del módulo se valida contra el registro declarativo de
 * `config/comandia.php` —no contra un enum— para que el registro siga siendo la
 * única fuente de verdad sobre qué módulos existen (D64).
 *
 * @property string $module
 * @property bool $is_enabled
 */
final class TenantModule extends DomainModel
{
    protected $table = 'tenant_modules';

    protected $fillable = ['module', 'is_enabled', 'enabled_at', 'disabled_at'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'enabled_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
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
     * Módulos que un tenant puede contratar, según el registro declarativo.
     *
     * @return list<string>
     */
    public static function activatableModules(): array
    {
        /** @var array<string, array{layer: string, activatable: bool, iteration: int}> $modules */
        $modules = (array) config('comandia.modules', []);

        return array_keys(array_filter($modules, fn (array $m): bool => $m['activatable'] === true));
    }
}
