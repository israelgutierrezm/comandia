<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure\Models;

use App\Modules\Finance\Domain\Enums\PaymentMethodKind;
use App\Modules\Organization\Domain\Enums\OperationalStatus;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use App\Modules\Shared\Infrastructure\Eloquent\DomainModel;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Finance\Domain\Exceptions\PaymentMethodInvariantException;

/**
 * Método de pago del negocio.
 *
 * @property OperationalStatus $status
 * @property PaymentMethodKind $kind
 */
final class PaymentMethod extends DomainModel
{
    use HasPublicUlid;

    protected $table = 'payment_methods';

    protected $fillable = [
        'code',
        'name',
        'kind',
        'affects_cash_drawer',
        'requires_reference',
        'allows_change',
        'status',
        'sort_order',
    ];

    /** Los valores por omisión también en el modelo: la lección de `Supplier` en la Iteración 3. */
    protected $attributes = [
        'status' => 'active',
        'affects_cash_drawer' => false,
        'requires_reference' => false,
        'allows_change' => false,
        'is_system' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => OperationalStatus::class,
            'kind' => PaymentMethodKind::class,
            'affects_cash_drawer' => 'boolean',
            'requires_reference' => 'boolean',
            'allows_change' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Los invariantes de un método del sistema, impuestos en el modelo y no sólo en el Form Request.
     *
     * ## Por qué aquí y no sólo en la validación
     *
     * Porque un servicio de aplicación, un comando de consola o un `tinker` no pasan por el Form Request. Es la misma
     * decisión que la Iteración 3 tomó con los motivos de merma de sistema (D186), y la que hizo que editar uno
     * devolviera 422 en lugar de 500: el invariante vive donde vive el dato.
     *
     * Lo que un método del sistema NO cambia: su código, su naturaleza, sus banderas de comportamiento, su nombre y su
     * condición de sistema. Lo que **sí**: su estado —un negocio que no acepta tarjeta la desactiva— y su orden en los
     * botones de la caja.
     *
     * Un negocio que quiera otro nombre crea un método propio. Renombrar «Efectivo» a «Lana» dejaría los cortes
     * históricos hablando de algo que ya no se llama así.
     */
    protected static function booted(): void
    {
        static::updating(function (self $method): void {
            // `getRawOriginal` y no `getOriginal`: lo segundo devuelve el valor ya CASTEADO, así que comparar contra la
            // cadena fallaría en silencio. Es el defecto que apareció tres veces en la Iteración 3.
            if (! $method->getRawOriginal('is_system')) {
                return;
            }

            foreach (['code', 'name', 'kind', 'affects_cash_drawer', 'requires_reference', 'allows_change', 'is_system'] as $congelado) {
                if ($method->isDirty($congelado)) {
                    throw PaymentMethodInvariantException::systemFieldIsFrozen(
                        $congelado,
                        (string) $method->getRawOriginal('code'),
                    );
                }
            }
        });

        static::deleting(function (self $method): void {
            if ($method->getRawOriginal('is_system')) {
                throw PaymentMethodInvariantException::systemCannotBeDeleted(
                    (string) $method->getRawOriginal('code'),
                );
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
     * ¿Un cobro con este método mueve el cajón de dinero?
     *
     * Se pregunta al modelo y no a la naturaleza porque un método `custom` lo declara por su cuenta. El diario **copia**
     * esta respuesta al asentar en lugar de releerla: cambiar la configuración mañana no puede cambiar los cortes de
     * ayer.
     */
    public function affectsCashDrawer(): bool
    {
        return $this->affects_cash_drawer;
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
