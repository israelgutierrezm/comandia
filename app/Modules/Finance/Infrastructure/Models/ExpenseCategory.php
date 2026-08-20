<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Finance\Domain\Exceptions\ExpenseCategoryInvariantException;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Categoría de gasto (§6.5).
 *
 * El mismo catálogo sirve para los gastos desde caja y para los de fuera: la diferencia entre ellos es de dónde salió el
 * dinero, no en qué se gastó.
 *
 * @property OperationalStatus $status
 */
final class ExpenseCategory extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'expense_categories';

    protected $fillable = ['name', 'status', 'sort_order'];

    protected $attributes = [
        'status' => 'active',
        'is_system' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Una categoría del sistema no se borra: los gastos ya registrados la citan, y un gasto que no puede decir en qué
     * se gastó no sirve para el reporte que justifica su existencia. Sí se desactiva y sí se reordena.
     *
     * Renombrarla SÍ se permite, a diferencia de un método de pago, y la razón es la asimetría real: el nombre de una
     * categoría es una etiqueta de reporte que el negocio ajusta a su vocabulario —«Luz» o «CFE»—, mientras el código de
     * un método de pago es la referencia con la que el diario agrupa el dinero.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $category): void {
            if ($category->getRawOriginal('is_system')) {
                throw ExpenseCategoryInvariantException::systemCannotBeDeleted((string) $category->getRawOriginal('name'));
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OperationalStatus::Active->value);
    }
}
