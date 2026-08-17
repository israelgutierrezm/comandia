<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de terminal.
 *
 * No cambia de sucursal ni de código: toda venta, pago, retiro y cancelación pertenece a una
 * sesión de caja abierta en una terminal concreta (§6.3). Mover la terminal a otra sucursal
 * reatribuiría ese histórico.
 */
final class UpdateTerminalRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:80'],

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
            'branch_ulid.prohibited' => 'Una terminal no cambia de sucursal: reatribuiría las sesiones de caja ya cerradas. Da de baja la terminal y crea otra.',
            'code.prohibited' => 'El código de la terminal no se puede cambiar.',
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
