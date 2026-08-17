<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Domain\Enums\UnitDimension;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Database\Factories\Catalog\UnitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidad de medida con su factor de conversión (D22).
 *
 * `factor_to_base` es cuántas unidades base del SISTEMA equivale una de ésta: `kg` → 1000 gramos.
 * La base de cada dimensión es constante del código, no dato del tenant
 * ({@see UnitDimension::baseUnitCode()}).
 *
 * @property string $code
 * @property string $name
 * @property UnitDimension $dimension
 * @property numeric-string $factor_to_base
 * @property CatalogStatus $status
 */
final class Unit extends DomainModel
{
    /** @use HasFactory<UnitFactory> */
    use HasPublicUlid;

    protected $table = 'units';

    protected $fillable = [
        'code',
        'name',
        'dimension',
        'factor_to_base',
        'status',
    ];

    /**
     * Default también en el modelo, no sólo en la migración: sin esto un `create()` que omita
     * `status` devuelve un objeto cuyo atributo es null hasta que alguien lo relea, y ése es el
     * objeto que se serializa en la respuesta (aprendido cinco veces en la Iteración 1).
     */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'dimension' => UnitDimension::class,
            'status' => CatalogStatus::class,

            // NO se castea a float: `factor_to_base` se usa en aritmética `bcmath` y un float
            // introduciría error justo en el factor que multiplica todas las cantidades del
            // sistema. Llega como cadena decimal desde MySQL y así se queda.
        ];
    }

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'base_unit_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * ¿Es la unidad base del sistema para su dimensión?
     *
     * Se responde por el factor y no por una columna `is_base`: el factor ya lo dice, y una columna
     * redundante necesitaría un índice único parcial —que MySQL no tiene— para impedir dos bases en
     * la misma dimensión.
     */
    public function isSystemBase(): bool
    {
        return bccomp($this->factor_to_base, '1', 8) === 0;
    }
}
