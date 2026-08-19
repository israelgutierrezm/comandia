<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Apertura de un conteo físico (D24, §6.2).
 *
 * `article_ulids` decide el **alcance**, y su ausencia no es un descuido: sin lista, se cuenta todo el almacén; con
 * lista, es un conteo cíclico («hoy las carnes»). Son las dos formas reales de contar y no hacía falta un campo
 * `type` que las distinguiera — la lista misma lo dice.
 */
final class StoreStockCountRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->id();

        return [
            'warehouse_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')->where('tenant_id', $tenantId),
            ],

            // `present` no; ausente es un caso legítimo y significa «todo el almacén».
            'article_ulids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'article_ulids.*' => [
                'required', 'string', 'size:26', 'distinct',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Sólo inventariables: un artículo que no se inventaría no tiene existencia que contar, y
                    // colarlo produciría un renglón que siempre saldría con diferencia cero.
                    ->where('is_inventoriable', true),
            ],

            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'article_ulids.max' => 'Un conteo admite hasta 500 artículos. Para inventarios más grandes, cuenta '.
                'todo el almacén sin especificar artículos.',
            'article_ulids.*.exists' => 'Alguno de los artículos no existe o no es inventariable.',
            'article_ulids.*.distinct' => 'Hay un artículo repetido en la lista.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_ulid' => 'el almacén',
            'article_ulids' => 'los artículos',
            'notes' => 'las notas',
        ];
    }
}
