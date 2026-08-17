<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Tenancy\Concerns;

use App\Modules\Shared\Domain\Tenancy\Exceptions\TenantMismatchException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Convierte un modelo Eloquent en modelo de dominio acotado por tenant (ADR-002).
 *
 * Hace tres cosas, y las tres hacen falta:
 *
 * 1. **Registra el global scope**, para que ninguna lectura pueda cruzar tenants.
 * 2. **Rellena `tenant_id` al crear**, para que ninguna escritura dependa de que
 *    el programador se acuerde. Un `tenant_id` olvidado sería una fila huérfana o,
 *    peor, una fila en el tenant equivocado.
 * 3. **Bloquea el cambio de `tenant_id` al actualizar.** El scope protege las
 *    lecturas; sin esto, un `update()` podría trasladar una venta al negocio de
 *    otro y el scope ni se enteraría porque la fila ya estaría del otro lado.
 *
 * Los puntos 2 y 3 son los que suelen faltar en las implementaciones caseras de
 * multi-tenancy, y son justo los que fallan en silencio.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(app(TenantScope::class));

        static::creating(function (Model $model): void {
            if ($model->getAttribute(TenantScope::COLUMN) === null) {
                $model->setAttribute(TenantScope::COLUMN, app(TenantContext::class)->id());
            }
        });

        static::updating(function (Model $model): void {
            if (! $model->isDirty(TenantScope::COLUMN)) {
                return;
            }

            /** @var int|null $original */
            $original = $model->getOriginal(TenantScope::COLUMN);
            /** @var int|null $current */
            $current = $model->getAttribute(TenantScope::COLUMN);

            throw TenantMismatchException::cannotChangeTenant(
                $model::class,
                $original === null ? null : (int) $original,
                $current === null ? null : (int) $current,
            );
        });
    }

    /**
     * El identificador del tenant dueño de esta fila.
     */
    public function tenantId(): int
    {
        return (int) $this->getAttribute(TenantScope::COLUMN);
    }
}
