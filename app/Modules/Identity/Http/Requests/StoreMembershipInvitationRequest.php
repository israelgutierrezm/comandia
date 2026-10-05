<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invitar —o volver a invitar— a una persona del negocio (diseño de acceso, fase 3).
 *
 * `role_ulids` es opcional: al reenviar, si no llega, la invitación nueva lleva los roles de la anterior. Llegando, el
 * primero es el rol con el que la persona entrará.
 */
final class StoreMembershipInvitationRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:150'],
            'role_ulids' => ['sometimes', 'array'],
            'role_ulids.*' => [
                'string', 'size:26',
                Rule::exists('roles', 'ulid')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe el correo al que le llegará la invitación.',
            'email.email' => 'Escribe un correo válido.',
            'role_ulids.*.exists' => 'Alguno de los roles indicados no existe.',
        ];
    }

    /**
     * @return list<string>|null  null si no se mandaron (se conservan los de la invitación anterior)
     */
    public function roleUlids(): ?array
    {
        return $this->has('role_ulids') ? array_values((array) $this->input('role_ulids', [])) : null;
    }
}
