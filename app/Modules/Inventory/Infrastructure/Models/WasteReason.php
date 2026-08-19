<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Models;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Un motivo de merma del catálogo del tenant (D27, §6.2).
 *
 * El vocabulario lo pone el negocio: una taquería tiene «se cayó al piso» y una cafetería «se pasó de tueste». Un
 * enum fijo del sistema obligaría a todos a usar «Otro», y un reporte donde el 90 % de las pérdidas son
 * inexplicables no sirve para nada.
 *
 * Se da de BAJA, no se borra: los movimientos que lo citan siguen existiendo, y el histórico tiene que poder
 * seguir diciendo por qué se perdió aquella mercancía.
 */
final class WasteReason extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'waste_reasons';

    protected $fillable = [
        'name',
        'requires_evidence',
        'status',
    ];

    /**
     * Por omisión y EN MEMORIA: un motivo nace activo y sin exigir evidencia.
     *
     * `status` se agregó al descubrir el mismo defecto en `Supplier` (paso 10): la columna tiene su default en la base,
     * pero el modelo recién creado no lo sabe hasta releerse, y `isActive()` revienta sobre un `create()` que no lo pase.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'requires_evidence' => false,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'requires_evidence' => 'boolean',
            'is_system' => 'boolean',
            'status' => CatalogStatus::class,
        ];
    }

    /**
     * Los motivos que se pueden ofrecer al capturar.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CatalogStatus::Active->value);
    }

    /**
     * Un motivo del sistema no se renombra ni se da de baja.
     *
     * Lo abre el paso 6: la recepción con diferencias genera una merma automática con el motivo «Diferencia en
     * tránsito». Si se pudiera renombrar a «se cayó al piso», las pérdidas del camión se agruparían bajo un motivo
     * que significa otra cosa y el reporte que D27 existe para dar quedaría mintiendo; si se pudiera dar de baja,
     * la siguiente recepción con diferencias fallaría.
     *
     * La exigencia de evidencia SÍ se puede cambiar: es política del negocio y no altera lo que el motivo
     * significa.
     */
    protected static function booted(): void
    {
        self::updating(function (self $reason): void {
            if (! $reason->getRawOriginal('is_system')) {
                return;
            }

            foreach (['name', 'status', 'is_system'] as $frozen) {
                if ($reason->isDirty($frozen)) {
                    throw new \RuntimeException(
                        'Este motivo de merma es del sistema: no se puede renombrar ni dar de baja. Su nombre es '
                        .'lo que hace legible el reporte de mermas.'
                    );
                }
            }
        });

        self::deleting(function (self $reason): void {
            if ($reason->getRawOriginal('is_system')) {
                throw new \RuntimeException('Este motivo de merma es del sistema y no se puede borrar.');
            }
        });
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
