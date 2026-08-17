<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de presentación de compra (D22): "Costal de 25 kg", "Caja con 24".
 *
 * `quantity_in_base_unit` está en la unidad base del artículo y **no** lleva unidad propia: la
 * presentación es un múltiplo, no otra unidad. Un costal de 25 kg vale 25 si la base del artículo es
 * `kg`, y 25000 si es `g`. Aceptar una unidad aquí abriría la puerta a que la presentación estuviera
 * en otra dimensión que la del artículo.
 */
final class StoreArticlePresentationRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],

            // Mayor que cero: es el divisor del costo unitario. Hay además un CHECK en la tabla —
            // esta regla da el mensaje, el CHECK da la garantía.
            'quantity_in_base_unit' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            'barcode' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity_in_base_unit.gt' => 'La cantidad que rinde la presentación tiene que ser mayor que cero.',
            'quantity_in_base_unit.decimal' => 'La cantidad admite hasta cuatro decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'quantity_in_base_unit' => 'la cantidad en unidad base',
            'barcode' => 'el código de barras',
        ];
    }
}
