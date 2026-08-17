<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\SaveRecipe;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Guardar la receta completa de un artículo (D16, D21).
 *
 * `PUT` y no `POST`/`DELETE` por línea: la receta es una unidad de sentido y se valida entera. Ver
 * {@see SaveRecipe}.
 *
 * Los ULID se resuelven aquí y las llaves internas se pasan al servicio. La API nunca expone PKs (§7), y
 * resolver con el scope de tenant aplicado significa que un ULID de otro negocio **simplemente no
 * existe** — el aislamiento no depende de que el cliente mande identificadores válidos.
 */
final class SaveRecipeRequest extends FormRequest
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
            // Cuánto rinde la receta. Mayor que cero: es el divisor del costo total, y un cero daría un
            // costo infinito propagado a todo lo que use esta sub-receta.
            'output_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            // Si se omite, se asume la unidad base del artículo, que es el caso normal: una receta de
            // enchiladas rinde "1 orden" y el artículo se mide en órdenes.
            'output_unit_ulid' => ['nullable', 'string', 'size:26', $this->unitMustExist()],

            'notes' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],

            'lines.*.component_ulid' => ['required', 'string', 'size:26', $this->componentMustExist()],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],
            'lines.*.unit_ulid' => ['required', 'string', 'size:26', $this->unitMustExist()],

            // D21: rendimiento por insumo, 100 % por omisión. Entre 0 exclusivo y 100 inclusive: un 0
            // sería división por cero y más de 100 significaría que del insumo sale más de lo que entró.
            'lines.*.yield_percent' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],

            'lines.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
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

                // El mismo ingrediente dos veces son dos cantidades que alguien sumará mal. Hay un índice
                // único que lo impide, pero llegar hasta él daría un error de base de datos en lugar de
                // un mensaje que diga qué línea repetir.
                $ulids = array_map(
                    fn (array $line): string => (string) ($line['component_ulid'] ?? ''),
                    (array) $this->input('lines', []),
                );

                $duplicated = array_keys(array_filter(array_count_values($ulids), fn (int $n): bool => $n > 1));

                if ($duplicated !== []) {
                    $names = Article::query()->whereIn('ulid', $duplicated)->pluck('name')->all();

                    $validator->errors()->add('lines', sprintf(
                        'Hay ingredientes repetidos: %s. Captura una sola línea con la cantidad total.',
                        implode(', ', $names),
                    ));
                }
            },
        ];
    }

    private function unitMustExist(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $unit = Unit::findByUlid((string) $value);

            if ($unit === null) {
                $fail('La unidad de medida no existe.');

                return;
            }

            if (! $unit->isActive()) {
                $fail("La unidad «{$unit->code}» está desactivada.");
            }
        };
    }

    private function componentMustExist(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $article = Article::findByUlid((string) $value);

            if ($article === null) {
                $fail('El ingrediente no existe.');

                return;
            }

            // Un ingrediente archivado dejaría la receta con un costo que ya no se puede actualizar.
            if (! $article->isActive()) {
                $fail("«{$article->name}» está archivado y no puede usarse como ingrediente.");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'output_quantity' => 'el rendimiento',
            'output_unit_ulid' => 'la unidad de rendimiento',
            'lines' => 'los ingredientes',
            'lines.*.component_ulid' => 'el ingrediente',
            'lines.*.quantity' => 'la cantidad',
            'lines.*.unit_ulid' => 'la unidad',
            'lines.*.yield_percent' => 'el rendimiento del insumo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Una receta necesita al menos un ingrediente.',
            'lines.min' => 'Una receta necesita al menos un ingrediente.',
            'lines.*.quantity.gt' => 'La cantidad tiene que ser mayor que cero.',
            'lines.*.yield_percent.max' => 'El rendimiento no puede pasar de 100 %.',
            'lines.*.yield_percent.gt' => 'El rendimiento tiene que ser mayor que cero.',
        ];
    }
}
