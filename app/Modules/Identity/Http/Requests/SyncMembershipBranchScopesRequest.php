<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alcance por sucursal de una membresía.
 *
 * ## `has_all_branches` y la lista son excluyentes, y la validación lo dice
 *
 * «Todas las sucursales» no es «las cinco que hay hoy»: es **también las futuras**. Son dos formas
 * distintas de decidir, no dos maneras de escribir lo mismo, y mezclarlas produce el peor resultado
 * posible — una lista explícita que parece la verdad mientras la bandera la ignora, hasta que alguien
 * abre una sucursal nueva y descubre quién entra y quién no.
 *
 * Por eso mandar la bandera en `true` **con** una lista se rechaza en lugar de resolverse por
 * precedencia: una precedencia silenciosa es una decisión que el sistema toma por el usuario.
 */
final class SyncMembershipBranchScopesRequest extends FormRequest
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
            'has_all_branches' => ['required', 'boolean'],

            // `present` y no `sometimes`: la lista completa siempre viaja, aunque esté vacía. Con
            // operaciones de agregar y quitar, dos peticiones simultáneas dejarían un alcance que no es
            // ninguno de los dos que se pidieron.
            'branch_ulids' => ['present', 'array', 'max:200'],
            'branch_ulids.*' => [
                'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', app(TenantContext::class)->id()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $todas = $this->boolean('has_all_branches');
                $lista = (array) $this->input('branch_ulids', []);

                if ($todas && $lista !== []) {
                    $validator->errors()->add(
                        'branch_ulids',
                        'Con «todas las sucursales» marcado no se envía una lista: la bandera incluye '.
                        'también las sucursales que se abran después, y una lista al lado quedaría '.
                        'desactualizada sin que nadie lo note.'
                    );
                }

                if (! $todas && $lista === []) {
                    $validator->errors()->add(
                        'branch_ulids',
                        'Elige al menos una sucursal, o marca «todas»: sin alcance, esta persona no puede '.
                        'operar en ningún sitio.'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulids.present' => 'Envía la lista de sucursales, aunque esté vacía.',
            'branch_ulids.*.exists' => 'Alguna de las sucursales indicadas no existe.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'has_all_branches' => 'el alcance a todas las sucursales',
            'branch_ulids' => 'las sucursales',
        ];
    }
}
