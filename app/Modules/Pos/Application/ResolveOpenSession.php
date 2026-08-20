<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Pos\Domain\Exceptions\PosAccountException;
use App\Modules\Pos\Infrastructure\Models\PosSession;

/**
 * La caja abierta de una sucursal.
 *
 * ## Por qué existe como servicio y no como método privado
 *
 * Porque lo necesitan el cobro y el descuento, y la regla es la misma: §6.3 dice que «toda venta, pago, retiro y
 * cancelación pertenece a una sesión». Un descuento también — es dinero que se dejó de cobrar, y el corte tiene que
 * poder explicarlo. Duplicar la consulta en dos servicios haría que el día que cambie —el corte por terminal del paso
 * 19— sólo cambiara uno.
 *
 * ## Se busca por SUCURSAL y no por terminal
 *
 * En un negocio con dos cajas, cobrar desde cualquiera pertenece al turno abierto de esa terminal, y la terminal la
 * resuelve el contexto. Con una sola caja —el caso normal— son lo mismo. Cuando el paso 19 traiga el corte por terminal
 * esto se afina aquí, en un solo sitio, sin tocar los pagos ya escritos.
 */
final readonly class ResolveOpenSession
{
    public function forBranch(int $branchId): PosSession
    {
        return PosSession::query()
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first()
            ?? throw PosAccountException::noOpenSession();
    }
}
