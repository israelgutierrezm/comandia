<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cambio de precio (D15).
 *
 * El precio va **con IVA incluido** (D30): es el dato maestro y el desglose se calcula.
 */
final class ChangeArticlePriceRequest extends FormRequest
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
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],

            // El motivo es opcional pero se pide en la UI: "¿por qué subió?" es la pregunta que el
            // historial existe para contestar, y el costo del momento sólo explica la mitad.
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Article $article */
                $article = $this->route('article');

                // Un precio en un artículo que no se vende es un número que alguien va a leer como precio
                // de venta. Si el negocio quiere venderlo, primero marca la capacidad.
                if (! $article->is_sellable) {
                    $validator->errors()->add(
                        'price',
                        'Este artículo no está marcado como vendible, así que no tiene precio de venta. '.
                        'Marca la capacidad primero.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.decimal' => 'El precio admite hasta dos decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'price' => 'el precio',
            'reason' => 'el motivo',
        ];
    }
}
