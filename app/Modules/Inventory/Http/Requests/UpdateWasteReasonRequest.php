<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
     * Los motivos del sistema se defienden AQUÍ y no sólo en el modelo.
     *
     * El invariante del modelo existe y es la garantía —ningún camino lo salta— pero lanza una excepción, y una
     * excepción de dominio sin mapear sale como 500. Quien intente renombrar «Diferencia en tránsito» merece un 422
     * que le diga por qué no puede, no un error del servidor que parece una falla del sistema.
     *
     * Lo encontré al escribir la prueba y marcarla `->throws()` para que pasara. Eso es la señal de que el problema
     * era el código y no la prueba: una prueba que espera una excepción de una petición HTTP está describiendo un
     * defecto, no un comportamiento.
     *
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var WasteReason $reason */
                $reason = $this->route('waste_reason');

                if (! $reason->is_system) {
                    return;
                }

                foreach (['name' => 'renombrar', 'status' => 'dar de baja'] as $field => $verb) {
                    if (! $this->has($field)) {
                        continue;
                    }

                    $validator->errors()->add($field, sprintf(
                        'No se puede %s «%s»: es un motivo del sistema. Su nombre es lo que hace legible el '
                        .'reporte de mermas, y las transferencias lo usan para registrar lo que no llegó. Sí puedes '
                        .'cambiar si exige evidencia.',
                        $verb,
                        $reason->name,
                    ));
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
