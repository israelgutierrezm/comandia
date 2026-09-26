<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Modules\Organization\Http\Resources\PreparationAreaResource;
use App\Modules\Organization\Http\Resources\TerminalResource;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lo que las pantallas del POS necesitan LEER de la organización, con el permiso de quien las opera.
 *
 * ## Por qué existe
 *
 * La caja y el tablero de cocina pedían sus listas a los endpoints de ADMINISTRACIÓN —`/terminals`
 * (`organization.terminals.view`) y `/preparation-areas` (`organization.preparation_areas.view`)—, que un
 * Cajero o una cocina no tienen: la pantalla entera fallaba para justo quienes la usan. Aquí se sirven las
 * mismas filas (mismos Resources) bajo el permiso de la OPERACIÓN —abrir turno, ver el tablero—, y sólo
 * las que la operación ofrece: activas y de la sucursal activa. La sucursal sale del CONTEXTO, nunca de la
 * petición, así que no hay alcance que comprobar.
 */
final class PosLookupController
{
    public function __construct(private readonly ContextHolder $context) {}

    /**
     * Terminales activas de la sucursal activa: entre las que se elige al abrir turno. Con su impresora, que es la del
     * cajón de dinero: la caja la necesita para ofrecer «Abrir cajón» sin pedir el catálogo de impresoras.
     */
    public function terminals(): AnonymousResourceCollection
    {
        $branch = $this->context->get()->activeBranch;

        $terminals = $branch === null
            ? collect()
            : Terminal::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->with('printer')
                ->orderBy('name')
                ->get();

        return TerminalResource::collection($terminals);
    }

    /** Áreas con tablero (KDS) de la sucursal activa: las pestañas de la pantalla de cocina. */
    public function kdsAreas(): AnonymousResourceCollection
    {
        $branch = $this->context->get()->activeBranch;

        $areas = $branch === null
            ? collect()
            : PreparationArea::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->where('uses_kds', true)
                ->orderBy('sort_order')
                ->get();

        return PreparationAreaResource::collection($areas);
    }
}
