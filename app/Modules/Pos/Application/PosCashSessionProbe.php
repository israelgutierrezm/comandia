<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Pos\Infrastructure\Models\PosSession;
use App\Modules\Shared\Domain\Contracts\CashSessionProbe;

/**
 * Las respuestas del punto de venta sobre sus sesiones de caja.
 *
 * Implementa el contrato del kernel para que `Finance` pueda preguntar sin conocer este módulo. Ver `CashSessionProbe`.
 *
 * Usa el mismo criterio que `ResolveOpenSession` —el turno abierto más reciente de la sucursal— y eso importa: si «la
 * caja abierta» significara una cosa al cobrar y otra al registrar un gasto, los dos acabarían en turnos distintos y el
 * arqueo no cuadraría.
 */
final readonly class PosCashSessionProbe implements CashSessionProbe
{
    public function openSessionIdForBranch(int $branchId): ?int
    {
        $id = PosSession::query()
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    public function sessionIdByUlid(string $ulid): ?int
    {
        $id = PosSession::query()->where('ulid', $ulid)->value('id');

        return $id === null ? null : (int) $id;
    }
}
