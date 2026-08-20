<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abrir una cuenta: con mesa, o de barra con nombre libre.
 *
 * ## Una de las dos, y exactamente una
 *
 * Con mesa es una cuenta de restaurante; con etiqueta es una de barra (§6.3). Las dos a la vez no significan nada —una
 * cuenta no está en la mesa 4 y además se llama «Señor de lentes»— y ninguna dejaría una cuenta que nadie puede
 * identificar en la pantalla de piso.
 */
final class OpenPosAccountRequest extends FormRequest
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
            'table_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('restaurant_tables', 'ulid')->where('tenant_id', $tenantId),
            ],

            // La sucursal sólo hace falta sin mesa: con mesa se toma del salón, que es donde la mesa está de verdad.
            'branch_ulid' => [
                'required_without:table_ulid', 'string', 'size:26',
                Rule::exists('branches', 'ulid')->where('tenant_id', $tenantId),
            ],

            'label' => ['required_without:table_ulid', 'nullable', 'string', 'max:60'],

            // El TITULAR (D233). Opcional: si no viene, lo es quien abre — lo correcto para una barra donde el cajero
            // atiende, y sigue permitiendo que un mesero abra la cuenta de otro.
            'waiter_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('tenant_memberships', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // Una membresía suspendida no puede ser titular de una cuenta nueva: la propina iría a nombre de
                    // alguien que ya no trabaja ahí.
                    ->where('status', 'active'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('table_ulid') && $this->filled('label')) {
                $validator->errors()->add(
                    'label',
                    'Una cuenta con mesa no lleva nombre libre: la mesa ya la identifica.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_ulid.required_without' => 'Indica la sucursal: una cuenta sin mesa necesita saber dónde se abre.',
            'label.required_without' => 'Ponle un nombre a la cuenta —«Barra 3», «Señor de lentes»— para poder encontrarla en el piso.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'table_ulid' => 'la mesa',
            'branch_ulid' => 'la sucursal',
            'label' => 'el nombre de la cuenta',
            'waiter_ulid' => 'el mesero',
        ];
    }
}
