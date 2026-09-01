<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El destino del «bump» del tablero: sólo `preparing` (empezó) o `served` (listo).
 *
 * `captured`/`commanded` no son destinos válidos del tablero —una línea no vuelve a «por preparar»— y `cancelled` es
 * otra acción, con su motivo y su PIN. La transición concreta (que sea hacia adelante) la valida el servicio.
 */
final class AdvanceKitchenItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el permiso `pos.kds.bump` lo exige el middleware de la ruta
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => [
                'required',
                Rule::in([PosOrderItemStatus::Preparing->value, PosOrderItemStatus::Served->value]),
            ],
        ];
    }

    public function attributes(): array
    {
        return ['to' => 'estado destino'];
    }
}
