<?php

declare(strict_types=1);

namespace App\Modules\Floor\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unir mesas a una principal (D32).
 */
final class JoinTablesRequest extends FormRequest
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
            // El máximo es seis y no cien: unir siete mesas de cuatro para un grupo de veintiocho es un banquete, y eso
            // se organiza con una mesa grande. Un tope alto sólo dejaría entrar un dedazo que después hay que deshacer
            // mesa por mesa.
            'table_ulids' => ['required', 'array', 'min:1', 'max:6'],

            'table_ulids.*' => [
                'required', 'string', 'size:26',
                Rule::exists('restaurant_tables', 'ulid')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'table_ulids.required' => 'Indica qué mesas se unen a ésta.',
            'table_ulids.max' => 'Para grupos de más de seis mesas, conviene dar de alta una mesa grande.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['table_ulids' => 'las mesas'];
    }
}
