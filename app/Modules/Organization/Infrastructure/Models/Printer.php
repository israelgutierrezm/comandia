<?php

declare(strict_types=1);

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Impresora de una sucursal: el destino físico de comandas y tickets.
 *
 * El servidor **no imprime**. Guarda a dónde hay que mandar cada trabajo para que el agente local —que sí ve la
 * impresora— sepa qué hacer. De ahí que esta entidad viva en `Organization` junto a la terminal y el almacén: es
 * hardware de la sucursal, no una capacidad del módulo de impresión.
 *
 * @property OperationalStatus $status
 * @property PrinterConnection $connection
 */
final class Printer extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'printers';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'connection',
        'target',
        'paper_width',
        'supports_cash_drawer',
        'status',
    ];

    /**
     * Los valores por omisión también en el modelo.
     *
     * Es la lección de la Iteración 3: `Supplier::create()` sin `status` dejaba el atributo **nulo en memoria** —la base
     * ponía su default, pero la instancia devuelta no lo sabía— y `isActive()` reventaba al leerlo. La base y el modelo
     * tienen que declarar lo mismo.
     */
    protected $attributes = [
        'status' => 'active',
        'paper_width' => 80,
        'supports_cash_drawer' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'connection' => PrinterConnection::class,
            'paper_width' => 'integer',
            'supports_cash_drawer' => 'boolean',
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
     * Las áreas que imprimen aquí.
     *
     * @return HasMany<PreparationArea, $this>
     */
    public function preparationAreas(): HasMany
    {
        return $this->hasMany(PreparationArea::class, 'printer_id');
    }

    /**
     * Las terminales que imprimen aquí.
     *
     * @return HasMany<Terminal, $this>
     */
    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class, 'printer_id');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * ¿Se le puede pedir que abra el cajón de dinero?
     *
     * Dos condiciones, y las dos importan: que tenga el conector y que esté activa. Preguntarlo aquí evita que cada
     * llamador —el POS al abrir cajón, la interfaz al pintar el botón— repita la conjunción y alguno se olvide de una
     * mitad.
     */
    public function canOpenCashDrawer(): bool
    {
        return $this->supports_cash_drawer && $this->isActive();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveInBranch(Builder $query, int $branchId): Builder
    {
        return $query
            ->where('branch_id', $branchId)
            ->where('status', OperationalStatus::Active->value);
    }
}
