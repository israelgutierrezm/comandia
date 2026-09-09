<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Enrolar un dispositivo como terminal compartida (ADR-012).
 *
 * El permiso lo exige la ruta (`can.write:organization.terminals.enroll`) y el alcance de sucursal lo
 * comprueba el controlador; aquí sólo entra la etiqueta con la que el administrador reconocerá el
 * aparato en la lista ("Caja mostrador", "Tablet terraza").
 */
final class EnrollTerminalDeviceRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => 'Ponle un nombre al dispositivo para reconocerlo después.',
            'label.max' => 'El nombre del dispositivo no puede exceder 80 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'el nombre del dispositivo',
        ];
    }
}
