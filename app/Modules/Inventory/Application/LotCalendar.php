<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use Carbon\CarbonImmutable;

/**
 * Qué día es HOY para la caducidad de un lote (D377).
 *
 * La caducidad es una FECHA del envase, y un lote vale todo su último día en la hora de la tienda: el que caduca el 22 se
 * puede usar hasta la medianoche del 22 en la sucursal. Con la hora del servidor (UTC), una fonda de la Ciudad de México lo
 * daba por caducado a las seis de la tarde del día anterior.
 */
final readonly class LotCalendar
{
    /**
     * Hoy donde está la mercancía: el día local de la sucursal del almacén. Un almacén sin sucursal (el central, el de
     * tránsito) usa el día del negocio.
     */
    public function todayAt(Warehouse $warehouse): CarbonImmutable
    {
        $zona = $warehouse->branch_id === null
            ? null
            : Branch::query()->whereKey($warehouse->branch_id)->value('timezone');

        return is_string($zona) && $zona !== ''
            ? CarbonImmutable::now($zona)->startOfDay()
            : $this->todayInBusiness();
    }

    /**
     * Hoy en el negocio: el día local MÁS atrasado de sus sucursales.
     *
     * Un lote es del artículo, no de un almacén (P3): la misma partida puede estar en dos sucursales. Se da por caducada
     * cuando lo está en todas; con el día de la sucursal más adelantada, a la del noroeste le quitaría una hora de un día
     * en que todavía vale.
     */
    public function todayInBusiness(): CarbonImmutable
    {
        $dias = Branch::query()
            ->pluck('timezone')
            ->filter(fn ($zona): bool => is_string($zona) && $zona !== '')
            ->unique()
            ->map(fn (string $zona): CarbonImmutable => CarbonImmutable::now($zona)->startOfDay());

        return $dias->isEmpty()
            ? CarbonImmutable::now()->startOfDay()
            : $dias->sortBy(fn (CarbonImmutable $dia): string => $dia->toDateString())->first();
    }
}
