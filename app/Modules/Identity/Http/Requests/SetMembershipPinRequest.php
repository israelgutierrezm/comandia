<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asignación del PIN de terminal (§4.1, D54).
 */
final class SetMembershipPinRequest extends FormRequest
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
            // Como CADENA y no como entero: un PIN que empieza por cero es válido, y como entero
            // perdería el cero — «0123» se guardaría como 123 y la persona no podría entrar con
            // el PIN que le dieron.
            'pin' => ['required', 'string', 'digits_between:4,6'],
            'pin_confirmation' => ['required', 'same:pin'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Captura el PIN.',
            'pin.digits_between' => 'El PIN debe tener entre 4 y 6 dígitos.',
            // Se confirma porque el PIN no se puede recuperar ni mostrar: un dedo torcido al
            // teclearlo dejaría a la persona sin poder autorizar y sin saber por qué.
            'pin_confirmation.same' => 'Los PIN no coinciden.',
            'pin_confirmation.required' => 'Confirma el PIN.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['pin' => 'el PIN', 'pin_confirmation' => 'la confirmación del PIN'];
    }
}
