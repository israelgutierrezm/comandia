<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Domain\Support\Concerns\Immutable;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cambio de precio — INMUTABLE (D15, §7).
 *
 * Guarda el estado del costeo en el momento del cambio, porque el costo y el markup de hace ocho meses ya no
 * se pueden reconstruir. Sin ellos, "¿el precio subió porque subió el costo, o porque alguien quiso?" no
 * tiene respuesta.
 *
 * El **margen** no se almacena: se calcula al leer, a partir del precio y del costo que sí están aquí.
 * Guardar los dos invitaría a que se contradijeran, y MARKUP y MARGEN no son sinónimos (D13, §7).
 *
 * @property numeric-string|null $previous_price
 * @property numeric-string $new_price
 * @property numeric-string|null $suggested_price
 * @property numeric-string|null $unit_cost_at_change
 * @property numeric-string|null $markup_percent
 */
final class PriceChange extends DomainModel
{
    use HasPublicUlid;
    use Immutable;

    protected $table = 'price_changes';

    protected $fillable = [
        'article_id',
        'branch_id',
        'previous_price',
        'new_price',
        'suggested_price',
        'unit_cost_at_change',
        'markup_percent',
        'reason',
        'actor_membership_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',

            // Los montos NO se castean a float: entran en el cálculo del margen y en comparaciones con
            // `bcmath` (§7, P3).
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * NULL = cambió el precio maestro; con valor = cambió el override de esa sucursal.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    /**
     * Sólo los cambios del precio maestro.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMasterPrice(Builder $query): Builder
    {
        return $query->whereNull('branch_id');
    }

    /**
     * El MARGEN de este cambio: utilidad ÷ precio (D13, §7).
     *
     * No es markup y no se llama markup. Se calcula y no se almacena porque es una consecuencia del precio
     * y del costo, que sí están guardados aquí.
     *
     * `null` cuando no había costo conocido: sin costo no hay utilidad que calcular, y devolver 100 % diría
     * que el artículo es todo ganancia.
     *
     * @return numeric-string|null porcentaje con dos decimales
     */
    public function marginPercent(): ?string
    {
        if ($this->unit_cost_at_change === null || bccomp($this->new_price, '0', 4) === 0) {
            return null;
        }

        $profit = bcsub($this->new_price, $this->unit_cost_at_change, 8);

        return Decimal::divide(
            bcmul($profit, '100', 8),
            $this->new_price,
            2,
        );
    }
}
