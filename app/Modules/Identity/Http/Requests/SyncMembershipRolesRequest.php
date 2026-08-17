<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asignación de roles a una membresía (D9).
 *
 * Se envía la lista completa y no altas y bajas por separado: el estado deseado es más difícil
 * de dejar a medias que una secuencia de operaciones, y el antes/después de la auditoría queda
 * legible de un vistazo.
 *
 * El PRIMER rol de la lista queda como rol por defecto: es el que el operador verá activo al
 * entrar, y con roles múltiples esa elección es información que sólo el cliente tiene.
 */
final class SyncMembershipRolesRequest extends FormRequest
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
            // `present` y no `required`: una lista vacía es una operación legítima —quitarle
            // todos los roles a alguien que sigue en nómina—, y `required` la rechazaría.
            'role_ulids' => ['present', 'array'],
            'role_ulids.*' => [
                'string', 'size:26',
                Rule::exists('roles', 'ulid')
                    ->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_ulids.present' => 'Indica la lista de roles, aunque venga vacía.',
            'role_ulids.*.exists' => 'Alguno de los roles indicados no existe en este negocio.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['role_ulids' => 'los roles'];
    }
}
