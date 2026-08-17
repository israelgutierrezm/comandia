<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de almacén.
 *
 * No se puede cambiar `kind` ni la sucursal, y por una razón de inventario, no de comodidad:
 * el kardex de este almacén ya tiene movimientos. Convertir un almacén de sucursal en central
 * —o moverlo a otra sucursal— reinterpretaría todo su histórico de existencias, y las áreas de
 * preparación que consumen de él empezarían a descontar de un sitio distinto sin que nadie lo
 * hubiera pedido.
 *
 * Tampoco el código: se usa como referencia en documentos de compra y transferencia.
 */
final class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],

            'kind' => ['prohibited'],
            'branch_ulid' => ['prohibited'],
            'code' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.prohibited' => 'El tipo de almacén no se puede cambiar: reinterpretaría todo su histórico de existencias. Da de baja el almacén y crea otro.',
            'branch_ulid.prohibited' => 'Un almacén no cambia de sucursal: su kardex quedaría atribuido a otra.',
            'code.prohibited' => 'El código del almacén no se puede cambiar: se usa como referencia en compras y transferencias.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'el nombre'];
    }
}
