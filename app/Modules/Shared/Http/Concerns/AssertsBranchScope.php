<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Concerns;

use App\Modules\Shared\Application\Context\ContextHolder;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La sucursal tiene que estar al ALCANCE de quien opera.
 *
 * ## Qué protege, y de qué NO protege el `tenant_id`
 *
 * El aislamiento de tenant —la línea que más se vigila en este proyecto— no dice absolutamente nada sobre dos
 * sucursales del mismo negocio. Quien lo dice es `membership_branch_scopes`, y su pregunta es `canOperateInBranch()`.
 * Sin esta comprobación, un cajero con alcance a Roma Norte abre la caja de Polanco y de esa sesión cuelgan después los
 * cobros, los retiros y el corte.
 *
 * ## Por qué vive en el kernel y no en cada módulo
 *
 * Porque ya iban tres copias. `AssertsWarehouseScope` en Inventarios, un método **estático de un controlador** de
 * Catalog al que llamaba Costing —una dependencia entre módulos por la puerta de atrás, justo lo que ADR-001 no
 * quiere— y ninguna en el POS. Los dos encabezados existentes ya advertían que duplicar esto acaba mal: «los fallos de
 * seguridad por duplicación no avisan», porque la copia que se queda atrás es la que autoriza **de más**.
 *
 * ## Dónde hace falta: cuando la sucursal viene del CUERPO
 *
 * `ResolveTenantContext::resolveTerminal()` ya hace las tres comprobaciones correctas sobre la cabecera `X-Terminal`.
 * Pero un endpoint que recibe `terminal_ulid`, `branch_ulid` o `table_ulid` en el cuerpo no pasa por ahí, y la
 * validación del Form Request sólo comprueba que exista dentro del negocio. Ese es el hueco: se encontró abriendo el
 * navegador, no con la suite.
 *
 * `Authorize::authorize($permiso, ?int $branchId = null)` acepta la sucursal como segundo argumento **opcional**, y ese
 * `= null` es la forma que toma el olvido — una llamada que lo omite se salta la comprobación sin que nada avise. Este
 * guardián es explícito a propósito: o se llama, o no se llama, y eso se ve leyendo el controlador.
 */
trait AssertsBranchScope
{
    /**
     * @param  int|null  $branchId  `null` cuando la entidad no pertenece a ninguna sucursal — un almacén central
     *                              surte a todas (D11), así que no hay alcance que comprobar. Rechazarlo aquí lo
     *                              dejaría inoperable para todo el mundo.
     *
     * @throws HttpException 403 si la sucursal está fuera del alcance de la membresía
     */
    protected function assertBranchInScope(?int $branchId, string $mensaje = 'No tienes acceso a esa sucursal.'): void
    {
        if ($branchId === null) {
            return;
        }

        $membership = app(ContextHolder::class)->getOrNull()?->membership;

        if ($membership === null || ! $membership->canOperateInBranch($branchId)) {
            throw new HttpException(403, $mensaje);
        }
    }
}
