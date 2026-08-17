<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de una membresía.
 *
 * No cambia de usuario: una membresía es la relación entre UNA persona y este negocio.
 * Reasignarla a otro usuario reatribuiría su historia —ventas, autorizaciones, filas de
 * auditoría— a alguien que no la hizo.
 */
final class UpdateMembershipRequest extends FormRequest
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
        /** @var TenantMembership $membership */
        $membership = $this->route('membership');

        return [
            'employee_code' => [
                'sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('tenant_memberships', 'employee_code')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($membership->id),
            ],

            'has_all_branches' => ['sometimes', 'boolean'],

            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'pin' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.unique' => 'Ya existe alguien con ese código de empleado.',
            'user_id.prohibited' => 'Una membresía no cambia de persona: reatribuiría su historial.',
            // Estado y PIN tienen endpoints propios porque son acciones con su propio permiso y
            // su propia entrada de auditoría, no campos de un formulario.
            'status.prohibited' => 'El estado se cambia con las acciones de suspender y reactivar.',
            'pin.prohibited' => 'El PIN se asigna en su propio endpoint.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['employee_code' => 'el código de empleado'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('employee_code')) {
            $this->merge(['employee_code' => mb_strtoupper((string) $this->input('employee_code'))]);
        }
    }
}
