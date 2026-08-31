<?php

declare(strict_types=1);

namespace App\Modules\Floor\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un elemento decorativo del salón: muro/columna, puerta o rótulo (ADR-011).
 *
 * No es una mesa: no tiene código, capacidad, estado ni cuenta, y por eso vive en su propia tabla. Comparte las
 * coordenadas lógicas y el render de las mesas (ADR-003), pero se dibuja detrás y no es seleccionable como cuenta.
 *
 * Cadenas y no float en la geometría, por la misma razón que en el plano: son centímetros con dos decimales.
 */
final class FloorElement extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'floor_elements';

    protected $fillable = ['floor_plan_id', 'kind', 'text', 'x', 'y', 'width', 'height', 'rotation', 'sort_order'];

    protected $attributes = [
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 20,
        'rotation' => 0,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'x' => 'string',
            'y' => 'string',
            'width' => 'string',
            'height' => 'string',
            'rotation' => 'string',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FloorPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class, 'floor_plan_id');
    }
}
