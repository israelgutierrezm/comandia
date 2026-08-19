<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Área de preparación: cocina, barra, parrilla.
 *
 * Entidad de PRIMERA CLASE (ESPECIFICACIÓN_MAESTRA §3): es a la vez destino de
 * comandas y punto de consumo de inventario. Esa doble naturaleza es la razón por
 * la que no es una etiqueta del artículo.
 *
 * `warehouse_id` es NOT NULL: el descuento de inventario por receta corre en la
 * cola `critical` y no debe contener lógica de adivinanza. Una suposición en el
 * camino del kardex es una existencia incorrecta.
 *
 * @property OperationalStatus $status
 */
final class PreparationArea extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'preparation_areas';

    protected $fillable = ['branch_id', 'warehouse_id', 'printer_id', 'code', 'name', 'status', 'sort_order'];

    /** Ver la nota de `Branch::$attributes`: el default también en el modelo. */
    protected $attributes = [
        'status' => 'active',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'sort_order' => 'integer',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * De dónde descuenta esta área.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * La impresora por donde salen las comandas de esta área.
     *
     * `null` es un estado legítimo: un área sin impresora existe mientras nadie configura el hardware, y una fonda
     * donde el cocinero está a dos metros puede no imprimir nunca. Lo que el POS NO hace es fallar en silencio — al
     * comandar a un área sin impresora lo dice, que es la lección de la Iteración 3 sobre los fallos mudos.
     *
     * @return BelongsTo<Printer, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Áreas activas de una sucursal, en orden de presentación.
     *
     * Es la consulta más caliente del módulo: el ruteo de comandas la ejecuta en
     * cada envío a cocina. Existe el índice
     * `preparation_areas_tenant_branch_status_index` para servirla.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveInBranch(Builder $query, int $branchId): Builder
    {
        return $query
            ->where('branch_id', $branchId)
            ->where('status', OperationalStatus::Active->value)
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
