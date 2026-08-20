<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un agente de impresión.
 */
final class StorePrintAgentRequest extends FormRequest
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

            // El nombre es lo que queda escrito en `print_jobs.claimed_by_agent`, así que sirve para rastrear: «Tableta
            // de la barra» dice dónde buscar cuando algo no salió; «Agente 1» no dice nada.
            'name' => ['required', 'string', 'min:2', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['branch_ulid' => 'sucursal', 'name' => 'nombre'];
    }
}
