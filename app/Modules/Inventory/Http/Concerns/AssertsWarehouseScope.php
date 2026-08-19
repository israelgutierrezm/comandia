<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Concerns;

use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Application\Context\ContextHolder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * El almacén tiene que estar al ALCANCE de quien opera.
 *
 * Cierra el mismo hueco que `assertBranchInScope` en el catálogo: el `tenant_id` protege del negocio ajeno, **no**
 * de la sucursal ajena dentro del propio. Sin esto, un almacenista con alcance sobre una sucursal podría mover
 * existencias de otra, y el movimiento quedaría firmado con su nombre en un almacén al que no tiene acceso.
 *
 * Vive en un `trait` compartido porque ya son dos los controladores que lo necesitan —movimientos y mermas— y en el
 * paso 6 serán tres con las transferencias. Con la comprobación copiada, el día que la regla cambie una de las
 * copias se quedaría atrás, y sería la copia que autoriza de más: los fallos de seguridad por duplicación no avisan.
 */
trait AssertsWarehouseScope
{
    /**
     * @throws HttpException 403 si el almacén pertenece a una sucursal fuera del alcance
     */
    protected function assertWarehouseInScope(Warehouse $warehouse): void
    {
        // El almacén de TRÁNSITO no lo opera nadie: lo escriben sólo las transferencias, y lo que hay ahí es lo que
        // va en camino (paso 6).
        //
        // Va aquí y no en cada controlador porque el paso 6 destapó que la salida de abajo —«sin sucursal, no hay
        // alcance que comprobar»— lo dejaba pasar: tránsito tampoco tiene sucursal. Sin este corte, una persona
        // podía registrar entradas, salidas, mermas y hasta un conteo físico en el almacén de la mercancía en
        // viaje, y cualquiera de las cuatro dejaría mercancía sin dueño.
        //
        // Con la comprobación repartida por controlador, el día que se añada el quinto se olvidaría — y el olvido
        // sería del lado que autoriza de más.
        if ($warehouse->kind === WarehouseKind::Transit) {
            throw new HttpException(
                422,
                'El almacén de mercancía en tránsito lo escriben sólo las transferencias: no admite operaciones '
                .'manuales.'
            );
        }

        // Un almacén CENTRAL no pertenece a ninguna sucursal: surte a todas (D11), así que no hay alcance que
        // comprobar. Exigir una sucursal aquí lo dejaría inoperable para todo el mundo, y es el caso que se rompe
        // al «endurecer» la regla sin pensar — por eso tiene prueba propia.
        if ($warehouse->branch_id === null) {
            return;
        }

        $membership = app(ContextHolder::class)->getOrNull()?->membership;

        if ($membership === null || ! $membership->canOperateInBranch($warehouse->branch_id)) {
            throw new HttpException(403, 'No tienes acceso al almacén de esa sucursal.');
        }
    }
}
