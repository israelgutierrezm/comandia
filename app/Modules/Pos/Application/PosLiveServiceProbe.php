<?php

declare(strict_types=1);

namespace App\Modules\Pos\Application;

use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Shared\Domain\Contracts\LiveServiceProbe;

/**
 * La respuesta del punto de venta a «¿esta mesa tiene servicio en curso?».
 *
 * Implementa el contrato del kernel para que `Floor` pueda preguntarlo sin conocer este módulo. Ver `LiveServiceProbe`.
 *
 * Usa el mismo scope `open()` que la pantalla de piso, y eso importa: si «cuenta viva» significara una cosa aquí y otra
 * allá, el salón mostraría una mesa ocupada que este servicio declararía libre.
 */
final readonly class PosLiveServiceProbe implements LiveServiceProbe
{
    public function tableHasLiveService(int $tableId): bool
    {
        return PosAccount::query()->where('table_id', $tableId)->open()->exists();
    }
}
