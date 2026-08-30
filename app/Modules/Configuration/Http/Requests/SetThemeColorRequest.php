<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Personalizar un color propio sobre el tema. Lista blanca cerrada de tokens: sólo los que tiene sentido dejar tocar sin
 * romper el contraste del resto de la paleta.
 */
final class SetThemeColorRequest extends FormRequest
{
    /** Los únicos tokens personalizables. El resto los fija el tema para garantizar legibilidad. */
    public const OVERRIDABLE = ['acento', 'barra_lateral', 'barra_lateral_activo'];

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
            'token' => ['required', 'string', Rule::in(self::OVERRIDABLE)],
            // Hex de 3 o 6 dígitos. Un color libre sí, pero un valor que no es color no.
            'value' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.regex' => 'El color debe ser un valor hexadecimal, por ejemplo #1E3A8A.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['token' => 'el color', 'value' => 'el valor'];
    }
}
