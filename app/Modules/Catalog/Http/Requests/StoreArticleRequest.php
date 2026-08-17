<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Enums\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de artículo (D17).
 *
 * Las cuatro capacidades son independientes y combinables, así que la validación no puede razonar
 * sobre un "tipo": tiene que validar **combinaciones**. Las dos reglas que importan:
 *
 *   - **Vendible exige precio** (invariante I2) y **categoría** (P11). Sin precio no se puede cobrar;
 *     sin categoría el POS no tiene dónde pintarlo.
 *   - **Al menos una capacidad**. Un artículo que no se vende, no se inventaría, no es insumo y no se
 *     produce no es nada: es una fila que nadie puede usar y que el usuario creyó que servía.
 *
 * El modelo vuelve a imponer las dos primeras al guardar. No es duplicación: el Form Request da el
 * mensaje por campo y el modelo da la garantía para seeders e importaciones.
 */
final class StoreArticleRequest extends FormRequest
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
            // SKU OPCIONAL (P10): un restaurante no le pone código a "Enchiladas suizas", y obligarlo
            // produce códigos inventados. Único por tenant cuando está presente.
            'code' => [
                'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_.]+$/',
                Rule::unique('articles', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],

            'name' => ['required', 'string', 'max:160'],
            'short_name' => ['nullable', 'string', 'max:40'],

            'category_ulid' => [
                'nullable', 'string', 'size:26',
                $this->categoryMustExistAndBeActive(),
            ],

            'base_unit_ulid' => [
                'required', 'string', 'size:26',
                $this->unitMustExistAndBeActive(),
            ],

            'is_sellable' => ['required', 'boolean'],
            'is_inventoriable' => ['required', 'boolean'],
            'is_supply' => ['required', 'boolean'],
            'is_producible' => ['required', 'boolean'],

            // IVA INCLUIDO (D30). Obligatorio si es vendible; se rechaza si no lo es, porque un
            // precio en un insumo es un dato que alguien va a leer como precio de venta.
            'base_price' => [
                Rule::requiredIf(fn (): bool => $this->boolean('is_sellable')),
                'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2',
            ],

            // MARKUP = utilidad ÷ costo (D13). No es margen y no se llama margen.
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],

            'is_available_in_pos' => ['nullable', 'boolean'],

            'tag_ulids' => ['nullable', 'array', 'max:20'],
            'tag_ulids.*' => ['string', 'size:26'],
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

                if ($this->boolean('is_sellable') && ! $this->filled('category_ulid')) {
                    $validator->errors()->add(
                        'category_ulid',
                        'Un artículo vendible necesita categoría: el punto de venta agrupa la pantalla por categoría.'
                    );
                }

                $hasCapability = $this->boolean('is_sellable')
                    || $this->boolean('is_inventoriable')
                    || $this->boolean('is_supply')
                    || $this->boolean('is_producible');

                if (! $hasCapability) {
                    $validator->errors()->add(
                        'is_sellable',
                        'Marca al menos una capacidad: un artículo que no se vende, no se inventaría, '.
                        'no es insumo y no se produce no se puede usar para nada.'
                    );
                }
            },
        ];
    }

    private function categoryMustExistAndBeActive(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $category = ArticleCategory::findByUlid((string) $value);

            if ($category === null) {
                $fail('La categoría no existe.');

                return;
            }

            if (! $category->status->isActive()) {
                $fail('Esa categoría está desactivada.');
            }
        };
    }

    private function unitMustExistAndBeActive(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $unit = Unit::findByUlid((string) $value);

            if ($unit === null) {
                $fail('La unidad de medida no existe.');

                return;
            }

            if ($unit->status !== CatalogStatus::Active) {
                $fail('Esa unidad de medida está desactivada.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un artículo con ese código.',
            'code.regex' => 'El código sólo admite letras, números, guiones, guión bajo y punto.',
            'base_price.required' => 'Un artículo vendible necesita precio.',
            'base_price.decimal' => 'El precio admite hasta dos decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'el código',
            'name' => 'el nombre',
            'short_name' => 'el nombre corto',
            'category_ulid' => 'la categoría',
            'base_unit_ulid' => 'la unidad base',
            'base_price' => 'el precio',
            'markup_percent' => 'el markup',
        ];
    }
}
