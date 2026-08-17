<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de un rol del tenant.
 *
 * `is_system` no es editable ni por asignación masiva ni por este endpoint: nadie debe poder
 * promover un rol a rol de sistema —ni degradar el Propietario— desde una petición. Sólo el
 * servicio de aprovisionamiento lo define.
 */
final class UpdateRoleRequest extends FormRequest
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
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:80',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('guard_name', 'web')
                    ->ignore($role->id),
            ],

            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'requires_two_factor' => ['sometimes', 'boolean'],

            'permissions' => ['sometimes', 'present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::names())],

            'is_system' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('permissions')) {
                return;
            }

            $gate = app(ModuleGate::class);

            /** @var list<string> $permisos */
            $permisos = (array) $this->input('permissions', []);

            $noDisponibles = array_values(array_filter(
                $permisos,
                fn (string $permiso): bool => ! $gate->isActiveForPermission($permiso),
            ));

            if ($noDisponibles !== []) {
                $validator->errors()->add('permissions', sprintf(
                    'Estos permisos pertenecen a módulos que no tienes contratados: %s.',
                    implode(', ', $noDisponibles),
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'is_system.prohibited' => 'Un rol no se puede marcar ni desmarcar como rol de sistema.',
            'permissions.*.in' => 'Alguno de los permisos indicados no existe en el catálogo del sistema.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'description' => 'la descripción',
            'permissions' => 'los permisos',
        ];
    }
}
