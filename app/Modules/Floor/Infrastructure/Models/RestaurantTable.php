<?php

declare(strict_types=1);

namespace App\Modules\Floor\Infrastructure\Models;

use App\Modules\Floor\Domain\Enums\TableStatus;
use App\Modules\Floor\Domain\Exceptions\TableInvariantException;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una mesa del salón (§6.4).
 *
 * El nombre del modelo lleva `Restaurant` porque su tabla también: `tables` choca con el vocabulario de MySQL, y
 * cualquier consulta de esquema escrita a mano acabaría ambigua. Está documentado en la migración.
 *
 * @property TableStatus $status
 */
final class RestaurantTable extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'restaurant_tables';

    protected $fillable = [
        'branch_id',
        'floor_zone_id',
        'code',
        'name',
        'seats',
        'status',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'shape',
        'joined_to_table_id',
    ];

    protected $attributes = [
        'status' => 'free',
        'seats' => 4,
        'shape' => 'rectangle',
        'x' => 0,
        'y' => 0,
        'width' => 80,
        'height' => 80,
        'rotation' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
            'seats' => 'integer',
        ];
    }

    /**
     * Los invariantes de la unión de mesas (D32).
     *
     * Se imponen en el modelo porque la unión se hace desde el POS, desde la pantalla de piso y —cuando llegue— desde la
     * app: tres caminos, un solo sitio donde vive la regla.
     */
    protected static function booted(): void
    {
        static::saving(function (self $table): void {
            $principal = $table->joined_to_table_id;

            if ($principal === null) {
                return;
            }

            // Una mesa no se une a sí misma. Parece obvio y es el primer error que produce una interfaz que manda el
            // ULID de la mesa seleccionada sin filtrarla de la lista.
            if ((int) $principal === (int) $table->id) {
                throw TableInvariantException::cannotJoinItself((string) $table->code);
            }

            // Y no se une a una mesa que ya está unida a otra: las uniones en cadena harían que «¿de quién es esta
            // cuenta?» tuviera que recorrer un árbol, y al pagar habría que deshacer ramas. Una unión es plana — hay una
            // principal y N mesas colgando de ella.
            $destino = self::query()->find($principal);

            if ($destino !== null && $destino->joined_to_table_id !== null) {
                throw TableInvariantException::cannotChainJoins(
                    (string) $table->code,
                    (string) $destino->code,
                );
            }
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<FloorZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(FloorZone::class, 'floor_zone_id');
    }

    /**
     * La mesa principal de la unión, si esta mesa está unida a otra.
     *
     * @return BelongsTo<self, $this>
     */
    public function joinedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'joined_to_table_id');
    }

    /**
     * Las mesas unidas a ésta.
     *
     * @return HasMany<self, $this>
     */
    public function joinedTables(): HasMany
    {
        return $this->hasMany(self::class, 'joined_to_table_id');
    }

    public function isJoined(): bool
    {
        return $this->joined_to_table_id !== null;
    }

    /**
     * ¿Se le puede sentar gente?
     *
     * Una mesa unida a otra NO está disponible por su cuenta aunque su estado diga «libre»: forma parte de un conjunto
     * que atiende una sola cuenta. Preguntarlo aquí evita que la pantalla de piso ofrezca sentar a alguien en la mitad
     * de una mesa de ocho.
     */
    public function isAvailable(): bool
    {
        return $this->status->isAvailable() && ! $this->isJoined();
    }

    /**
     * La capacidad real, contando las mesas unidas.
     *
     * Es el dato que alguien necesita al sentar un grupo, y calcularlo en la interfaz obligaría a traer las mesas unidas
     * en cada refresco del salón.
     */
    public function effectiveSeats(): int
    {
        return $this->seats + (int) $this->joinedTables()->sum('seats');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', TableStatus::Free->value)
            ->whereNull('joined_to_table_id');
    }
}
