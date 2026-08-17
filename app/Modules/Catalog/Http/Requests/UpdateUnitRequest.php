<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición de unidad de medida.
 *
 * ## Qué NO se puede cambiar, y por qué
 *
 * Ni el **código** ni la **magnitud** ni el **factor**. No es rigidez: las tres reinterpretarían el
 * histórico.
 *
 * - El código entra en cada línea de receta que un humano leyó y aprobó.
 * - La magnitud haría que las conversiones ya calculadas dejaran de ser válidas.
 * - El factor es el caso más grave: cambiar `kg` de 1000 a 1 no corrige un error, **cambia el
 *   significado de todas las cantidades ya capturadas** en esa unidad, y con ellas todos los costos
 *   derivados. Silenciosamente.
 *
 * Si una unidad está mal, se desactiva y se crea la correcta. Es más trabajo y es el único camino que
 * no falsifica el pasado.
 */
final class UpdateUnitRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'status' => 'el estado',
        ];
    }
}
