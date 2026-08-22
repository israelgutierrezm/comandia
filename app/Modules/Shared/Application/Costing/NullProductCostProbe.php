<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Costing;

use App\Modules\Shared\Domain\Contracts\ProductCostProbe;

/**
 * El default: costo desconocido → `"0"`.
 *
 * Se usa cuando `Costing` no está enlazado (una prueba que no lo levanta) o como red de seguridad. Congelar `"0"` deja la
 * venta en pie: el POS no se bloquea por no saber un costo (§6). El margen de esa línea quedará sin costo, visible como
 * tal en el reporte.
 */
final readonly class NullProductCostProbe implements ProductCostProbe
{
    public function currentUnitCost(int $articleId): string
    {
        return '0';
    }
}
