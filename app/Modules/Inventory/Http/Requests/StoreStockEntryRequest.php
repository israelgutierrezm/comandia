<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Enums\StockMovementKind;

/**
 * Entrada manual de inventario: entró algo que no fue compra.
 *
 * Muestras del proveedor, una devolución de cliente, mercancía que apareció. Con `is_initial_load` se
 * registra como **carga inicial**, que es la misma operación con otro significado: no es que haya entrado
 * algo, es que se está declarando lo que ya había al empezar a usar el sistema.
 *
 * La distinción importa en los reportes: la carga inicial no es una entrada del periodo y sumarla al
 * movimiento del mes daría un número inflado el primer mes de operación.
 */
final class StoreStockEntryRequest extends StoreStockMovementRequest
{
    public function movementKind(): StockMovementKind
    {
        return $this->boolean('is_initial_load')
            ? StockMovementKind::InitialLoad
            : StockMovementKind::ManualEntry;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'is_initial_load' => ['sometimes', 'boolean'],
        ];
    }
}
