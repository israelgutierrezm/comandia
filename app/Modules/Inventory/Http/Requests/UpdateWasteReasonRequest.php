<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de un motivo de merma.
 *
 * El **nombre sí se puede corregir**, y es una diferencia deliberada respecto del código de un lote o la cantidad de
 * una presentación: el motivo no es un divisor ni una llave de nada, y corregir «se calló al piso» no reinterpreta
 * ninguna merma pasada — las sigue describiendo, mejor escrito.
 *
 * El motivo se da de BAJA y no se borra: los movimientos que lo citan tienen que poder seguir diciendo por qué se
 * perdió aquella mercancía.
 */
final class UpdateWasteReasonRequest extends FormRequest
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
        /** @var WasteReason $reason */
        $reason = $this->route('waste_reason');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:80',
                Rule::unique('waste_reasons', 'name')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    ->ignore($reason->id),
            ],

            'requires_evidence' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un motivo de merma con ese nombre.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'el nombre',
            'requires_evidence' => 'la exigencia de evidencia',
            'status' => 'el estado',
        ];
    }
}
