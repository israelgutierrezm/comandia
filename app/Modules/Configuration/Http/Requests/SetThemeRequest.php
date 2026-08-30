<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Elegir el tema propio. La preferencia es de la persona; la autorización es sólo estar autenticado.
 */
final class SetThemeRequest extends FormRequest
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
            // Del catálogo del propio negocio: un ULID de otro tenant no existe para esta consulta y se rechaza.
            'theme_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('themes', 'ulid')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['theme_ulid' => 'el tema'];
    }
}
