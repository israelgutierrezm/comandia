<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de artículo.
 *
 * ## Dos campos ausentes a propósito
 *
 * **`base_unit_ulid`** no se puede cambiar: todas las cantidades históricas del artículo —costos,
 * recetas y, desde la Iteración 3, existencias— están expresadas en esa unidad. El modelo lanza
 * excepción si alguien lo intenta por otro camino.
 *
 * **`base_price`** tampoco: el precio se cambia por su propio endpoint, con su propio permiso
 * (`catalog.prices.update`) y dejando historial (D15). Si se pudiera cambiar aquí, bastaría el permiso
 * de editar artículos para mover precios sin dejar rastro — y los precios son zona de auditoría
 * (§6.7). Ese endpoint llega en el paso 8 de la iteración; hasta entonces el precio se fija al crear.
 */
final class UpdateArticleRequest extends FormRequest
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
        /** @var Article $article */
        $article = $this->route('article');

        return [
            'code' => [
                'sometimes', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-_.]+$/',
                Rule::unique('articles', 'code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($article->id),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:40'],

            'category_ulid' => [
                'sometimes', 'nullable', 'string', 'size:26',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (ArticleCategory::findByUlid((string) $value) === null) {
                        $fail('La categoría no existe.');
                    }
                },
            ],

            'is_sellable' => ['sometimes', 'boolean'],
            'is_inventoriable' => ['sometimes', 'boolean'],
            'is_supply' => ['sometimes', 'boolean'],
            'is_producible' => ['sometimes', 'boolean'],

            'markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99', 'decimal:0,2'],
            'is_available_in_pos' => ['sometimes', 'boolean'],

            'tag_ulids' => ['sometimes', 'array', 'max:20'],
            'tag_ulids.*' => ['string', 'size:26'],
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
            'markup_percent' => 'el markup',
        ];
    }
}
