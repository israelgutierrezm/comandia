<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Identificación del operador de una terminal compartida por código de empleado + PIN (ADR-012).
 *
 * Misma forma de entrada que la autorización por PIN (código + PIN de 4 a 6 dígitos), pero sin
 * `permission`: esto NO autoriza una acción, sólo identifica a quién opera; sus permisos salen luego de
 * su rol activo (D9). No exige autenticación previa —es la pantalla de bloqueo del dispositivo—; el
 * bloqueo por intentos y el límite por dispositivo/IP protegen el PIN (D54/D55).
 */
final class IdentifyOperatorRequest extends FormRequest
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
            'employee_code' => ['required', 'string', 'max:20'],

            // 4 a 6 dígitos (§4.1). `string` y no `integer` a propósito: un PIN que empieza por cero es
            // un PIN válido, y como entero perdería el cero.
            'pin' => ['required', 'string', 'digits_between:4,6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.required' => 'Captura tu código de empleado.',
            'employee_code.max' => 'El código de empleado no puede exceder 20 caracteres.',
            'pin.required' => 'Captura tu PIN.',
            'pin.digits_between' => 'El PIN debe tener entre 4 y 6 dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_code' => 'código de empleado',
            'pin' => 'PIN',
        ];
    }
}
