<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dar de alta una regla de ruteo a un área (D240).
 */
final class StorePosAreaRouteRequest extends FormRequest
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
            'branch_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            // Uno de los dos, nunca los dos. `required_without` cruzado dice «al menos uno» y `prohibited_unless` no
            // encaja aquí, así que la exclusión va en `withValidator` — y además está en un CHECK de la base y en un
            // invariante del modelo, porque es la regla que hace que el orden de resolución sea predecible.
            'article_ulid' => [
                'nullable', 'required_without:article_category_ulid', 'string', 'size:26',
                Rule::exists('articles', 'ulid')->where('tenant_id', $tenantId)->where('is_sellable', true),
            ],

            'article_category_ulid' => [
                'nullable', 'required_without:article_ulid', 'string', 'size:26',
                Rule::exists('article_categories', 'ulid')->where('tenant_id', $tenantId),
            ],

            'preparation_area_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('preparation_areas', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Un área archivada no puede recibir comandas: el papel saldría por una impresora que ya nadie
                    // atiende.
                    ->where('status', 'active'),
            ],
        ];
    }

    protected function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $validator): void {
            if ($this->filled('article_ulid') && $this->filled('article_category_ulid')) {
                $validator->errors()->add(
                    'article_ulid',
                    'Una regla apunta a un artículo o a una categoría, no a las dos: la del artículo ya gana sobre la de '
                    .'su categoría, así que mandar ambas no significa nada.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_ulid' => 'sucursal',
            'article_ulid' => 'artículo',
            'article_category_ulid' => 'categoría',
            'preparation_area_ulid' => 'área de preparación',
        ];
    }
}
