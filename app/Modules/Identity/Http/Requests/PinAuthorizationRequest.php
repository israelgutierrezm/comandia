<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Entrada de la autorización por PIN.
 *
 * Toda entrada pasa por Form Request (definition of done, punto 3). Mensajes en español
 * mexicano con acentuación completa: los lee un cajero en una terminal, no un desarrollador
 * en un log.
 */
final class PinAuthorizationRequest extends FormRequest
{
    /**
     * La autorización no exige permiso previo: es precisamente el mecanismo por el que
     * alguien SIN el permiso pide que otro se lo autorice. Quien no puede es el
     * autorizador, y eso lo evalúa el servicio.
     */
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

            // 4 a 6 dígitos (§4.1). `string` y no `integer` a propósito: un PIN que empieza
            // por cero es un PIN válido, y como entero perdería el cero.
            'pin' => ['required', 'string', 'digits_between:4,6'],

            // El permiso tiene que existir en el catálogo cerrado: sin esta regla, el
            // endpoint aceptaría autorizar permisos inventados y devolvería una concesión
            // que ninguna operación puede consumir.
            'permission' => ['required', 'string', Rule::in(PermissionCatalog::names())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.required' => 'Captura el código de empleado de quien autoriza.',
            'employee_code.max' => 'El código de empleado no puede exceder 20 caracteres.',
            'pin.required' => 'Captura el PIN.',
            'pin.digits_between' => 'El PIN debe tener entre 4 y 6 dígitos.',
            'permission.required' => 'Falta indicar qué acción se va a autorizar.',
            'permission.in' => 'La acción que se intenta autorizar no existe.',
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
            'permission' => 'acción',
        ];
    }
}
