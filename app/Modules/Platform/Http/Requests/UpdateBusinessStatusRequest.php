<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de estado de un negocio desde la plataforma. La autorización la aplica `EnsureSuperAdmin` en la ruta; aquí se
 * limita a los estados que el panel sabe fijar (activo, suspendido, sólo lectura). Que la transición sea LEGAL desde el
 * estado actual lo decide `ChangeTenantStatus` —depende de dónde viene, no sólo de a dónde va—.
 */
final class UpdateBusinessStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in([
                TenantStatus::Active->value,
                TenantStatus::Suspended->value,
                TenantStatus::ReadOnly->value,
            ])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Elige el nuevo estado.',
            'status.in' => 'Ese no es un estado que se pueda fijar desde aquí.',
        ];
    }
}
