<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override de configuración a nivel tenant (ARQUITECTURA_MAESTRA §5).
 *
 * El default de sistema vive EN CÓDIGO, así que esta tabla guarda sólo overrides.
 * El valor es una sola columna de texto tipada por el catálogo (D79): el catálogo
 * ya es la autoridad sobre el tipo y valida en la escritura.
 *
 * Sin ULID: no se expone como recurso propio. La API de configuración habla de
 * llaves y valores, no de filas.
 */
final class TenantSetting extends DomainModel
{
    protected $table = 'tenant_settings';

    protected $fillable = ['setting_key', 'setting_value'];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
