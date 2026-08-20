<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cerrar caja.
 *
 * No pide declaraciones en el cuerpo: se declaran antes, con su propio endpoint, y el servicio EXIGE que existan. Es
 * deliberado — declarar y cerrar son dos hechos distintos, y juntarlos permitiría cerrar y declarar en el mismo gesto,
 * que es justo lo que el precorte ciego intenta evitar.
 */
final class CloseCashSessionRequest extends FormRequest
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
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['notes' => 'las notas'];
    }
}
