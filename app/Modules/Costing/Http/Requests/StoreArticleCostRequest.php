<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Captura manual de costo (D14).
 *
 * Admite las **dos formas** en que un humano conoce un costo, y exactamente una de las dos por
 * petición:
 *
 *   - `unit_cost`: ya sabe cuánto cuesta la unidad base ("el kilo de jitomate está a $24").
 *   - `presentation_ulid` + `total_cost`: sabe lo que pagó por un empaque ("un costal de 25 kg en
 *     $600"). El sistema divide.
 *
 * Aceptar las dos a la vez sería aceptar dos afirmaciones que pueden contradecirse, y elegir una en
 * silencio es la clase de decisión que produce un costo equivocado sin que nadie lo note.
 */
final class StoreArticleCostRequest extends FormRequest
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
            // Cuatro decimales: un costo unitario NO es un monto, es un monto por unidad, y el gramo
            // de sal cuesta $0.000012 (§7, excepción de P3).
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],

            'presentation_ulid' => [
                'nullable', 'string', 'size:26',
                $this->presentationMustBelongToArticle(),
            ],

            // Lo que se pagó por UNA presentación. Es un monto, así que dos decimales (§7).
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],

            // Cuándo EMPEZÓ a valer, que puede no ser hoy: la factura de la semana pasada se captura
            // hoy con su fecha. Una captura retroactiva NO pisa el costo vigente.
            'effective_at' => ['nullable', 'date', 'before_or_equal:now'],

            'notes' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $byUnit = $this->filled('unit_cost');
                $byPresentation = $this->filled('presentation_ulid');

                if ($byUnit && $byPresentation) {
                    $validator->errors()->add(
                        'unit_cost',
                        'Captura el costo por unidad O por presentación, no las dos: son dos '.
                        'afirmaciones que pueden contradecirse.'
                    );

                    return;
                }

                if (! $byUnit && ! $byPresentation) {
                    $validator->errors()->add(
                        'unit_cost',
                        'Indica el costo por unidad, o la presentación de compra y lo que pagaste por ella.'
                    );

                    return;
                }

                if ($byPresentation && ! $this->filled('total_cost')) {
                    $validator->errors()->add(
                        'total_cost',
                        'Indica cuánto pagaste por esa presentación.'
                    );
                }
            },
        ];
    }

    private function presentationMustBelongToArticle(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $presentation = ArticlePurchasePresentation::findByUlid((string) $value);

            if ($presentation === null) {
                $fail('La presentación de compra no existe.');

                return;
            }

            /** @var Article $article */
            $article = $this->route('article');

            // Una presentación de OTRO artículo daría un costo unitario calculado con el factor
            // equivocado: un número plausible y falso, que es el peor resultado posible.
            if ($presentation->article_id !== $article->id) {
                $fail('Esa presentación pertenece a otro artículo.');

                return;
            }

            if (! $presentation->isActive()) {
                $fail('Esa presentación está dada de baja.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit_cost' => 'el costo por unidad',
            'presentation_ulid' => 'la presentación de compra',
            'total_cost' => 'el costo total',
            'effective_at' => 'la fecha de vigencia',
            'notes' => 'las notas',
        ];
    }
}
