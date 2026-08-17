<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Models;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Override de configuración a nivel sucursal — el nivel más específico de la
 * cascada (ARQUITECTURA_MAESTRA §5).
 *
 * Tabla separada de `tenant_settings` (D78) por una razón técnica concreta: en
 * MySQL un índice único trata cada NULL como distinto, así que una tabla común con
 * `branch_id` nullable no podría garantizar una sola fila por llave a nivel tenant.
 * Las salidas habituales —un centinela `branch_id = 0`, o una columna generada—
 * rompen la FK real o meten magia en el esquema.
 */
final class BranchSetting extends DomainModel
{
    protected $table = 'branch_settings';

    protected $fillable = ['branch_id', 'setting_key', 'setting_value'];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
