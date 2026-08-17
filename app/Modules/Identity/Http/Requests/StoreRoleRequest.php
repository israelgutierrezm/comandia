<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\PermissionCatalog;
use App\Modules\Shared\Application\Authorization\ModuleGate;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creación de un rol del tenant (D10).
 *
 * El tenant **combina permisos en roles; no inventa permisos**. Por eso la lista se valida
 * contra el catálogo cerrado y además contra los módulos que el tenant tiene contratados: un rol
 * con permisos de e-commerce en un negocio sin e-commerce sería una promesa que `ModuleGate`
 * incumpliría en silencio al verificarla.
 */
final class StoreRoleRequest extends FormRequest
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
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->where('guard_name', 'web'),
            ],

            'description' => ['nullable', 'string', 'max:160'],
            'requires_two_factor' => ['boolean'],

            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::names())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
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
            'permissions.present' => 'Indica la lista de permisos, aunque venga vacía.',
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
