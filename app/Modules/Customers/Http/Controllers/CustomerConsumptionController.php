<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\Customers\Http\Resources\ConsumptionEntryResource;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Shared\Domain\Contracts\ConsumptionHistoryProvider;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Los consumos del cliente, para su expediente (§6.6).
 *
 * ## De dónde salen, y por qué no los lee este módulo
 *
 * Los consumos son cuentas del POS. `Customers` no puede consultarlas: `Pos` ya depende de `Customers`, así que hacerlo
 * al revés cerraría un ciclo. En su lugar, el expediente **pregunta** por una sonda del kernel
 * (`ConsumptionHistoryProvider`) que `Pos` implementa (D318). Este controlador resuelve el cliente —su propio modelo— y
 * pasa el id interno; la sonda devuelve primitivos.
 */
final class CustomerConsumptionController
{
    public function __construct(
        private readonly ConsumptionHistoryProvider $history,
    ) {}

    public function __invoke(Customer $customer): AnonymousResourceCollection
    {
        // Los últimos cincuenta: el expediente es una vista de un vistazo, no un reporte. El historial completo con
        // filtros y exportación es trabajo de Reportes (Iteración 7); aquí basta lo reciente.
        $entries = $this->history->forCustomer((int) $customer->id, 50);

        return ConsumptionEntryResource::collection($entries);
    }
}
