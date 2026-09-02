<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de una opción de modificador (D7).
 *
 * `extra_price` va con IVA incluido (D30) y **no admite negativos** (P14): un modificador que resta es un
 * descuento, y los descuentos tienen permiso, motivo y actor propios (§6.3). Permitirlos aquí sería una puerta
 * para descontar sin dejar rastro.
 *
 * Un precio de **cero es válido y frecuente**: «sin cebolla» no cuesta y sigue siendo un modificador —se
 * imprime en comanda y puede tener impacto en receta—.
 */
final class StoreModifierRequest extends FormRequest
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
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:80'],
            'extra_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', 'in:active,inactive'],

            // Agotado (86'ing, D7): deshabilita la opción sin quitarla de la carta —el modal del POS la muestra tachada—.
            // Es distinto de `status:inactive` (retirada del catálogo): «agotado» es de hoy y se revierte al reponer.
            'sold_out' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'extra_price.min' => 'Un modificador no puede restar al precio: para eso están los descuentos, '.
                'que llevan permiso, motivo y actor registrado.',
            'extra_price.decimal' => 'El precio adicional admite hasta dos decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'extra_price' => 'el precio adicional',
            'sort_order' => 'el orden',
        ];
    }
}
