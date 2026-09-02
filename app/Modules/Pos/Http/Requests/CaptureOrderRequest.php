<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Capturar una orden con sus líneas.
 *
 * ## Lo que NO se manda: el precio
 *
 * El cliente manda el artículo y la cantidad; el precio lo resuelve el servidor y lo congela (§6.9: «el frontend
 * previsualiza; el backend decide»). Aceptar un precio del cliente sería la puerta más ancha del sistema — cualquiera
 * podría cobrarse un café a un peso desde la consola del navegador.
 */
final class CaptureOrderRequest extends FormRequest
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
            // El candado optimista, opcional. Ver `PosAccountController::assertVersion()`.
            'version' => ['sometimes', 'integer', 'min:0'],

            // Cincuenta líneas por orden: una mesa grande pide mucho, y un tope bajo obligaría a partir la captura en
            // dos órdenes, con lo que la cocina recibiría dos comandas de la misma ronda.
            'lines' => ['required', 'array', 'min:1', 'max:50'],

            'lines.*.article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    // Sólo lo VENDIBLE: un insumo no tiene precio de venta, y dejarlo pasar acabaría en un 422 del
                    // servicio después de que el mesero ya lo capturó.
                    ->where('is_sellable', true),
            ],

            // Cuatro decimales: se puede vender media pizza o 250 g de queso al peso.
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.9999', 'decimal:0,4'],

            'lines.*.modifier_ulids' => ['sometimes', 'array', 'max:20'],
            'lines.*.modifier_ulids.*' => [
                'required', 'string', 'size:26',
                Rule::exists('modifiers', 'ulid')->where('tenant_id', $tenantId)->where('status', 'active'),
            ],

            // Las cantidades por modificador, indexadas por su ULID: son los 3 shots de D7.
            'lines.*.modifier_quantities' => ['sometimes', 'array'],
            'lines.*.modifier_quantities.*' => ['integer', 'min:1', 'max:99'],

            // Nota libre a cocina («sin picante», «para el niño»). Instrucción, no modificador con precio.
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],

            // El precio NO se acepta del cliente. Se declara prohibido en lugar de ignorarse para que quien lo intente
            // reciba un mensaje en lugar de creer que funcionó y descubrir el precio real en el ticket.
            'lines.*.unit_price' => ['prohibited'],
            'lines.*.price' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ((array) $this->input('lines') as $indice => $linea) {
                $this->validateModifierQuantities($validator, (int) $indice, (array) $linea);
            }
        });
    }

    /**
     * La cantidad de un modificador sólo vale si su grupo la permite (D7).
     *
     * Un grupo «Término de la carne» con `allows_quantity` apagado no admite «3 términos medios»: la cantidad ahí no
     * significa nada y produciría un cargo triple por algo que se sirve una vez. Se valida aquí, contra el grupo real,
     * porque es una regla del catálogo y no una preferencia de la pantalla.
     *
     * @param  array<string, mixed>  $linea
     */
    private function validateModifierQuantities(Validator $validator, int $indice, array $linea): void
    {
        $cantidades = (array) ($linea['modifier_quantities'] ?? []);

        if ($cantidades === []) {
            return;
        }

        $modificadores = Modifier::query()
            ->whereIn('ulid', array_keys($cantidades))
            ->with('group')
            ->get();

        foreach ($modificadores as $modifier) {
            $cantidad = (int) ($cantidades[$modifier->ulid] ?? 1);

            if ($cantidad > 1 && $modifier->group !== null && ! $modifier->group->allows_quantity) {
                $validator->errors()->add(
                    "lines.{$indice}.modifier_quantities.{$modifier->ulid}",
                    sprintf('«%s» no admite cantidad: se sirve una vez.', $modifier->name),
                );
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Una orden necesita al menos un renglón.',
            'lines.*.article_ulid.exists' => 'Ese artículo no existe, está dado de baja o no es vendible.',
            'lines.*.unit_price.prohibited' => 'El precio no se envía: lo resuelve el servidor y lo congela en el renglón.',
            'lines.*.price.prohibited' => 'El precio no se envía: lo resuelve el servidor y lo congela en el renglón.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['lines' => 'los renglones', 'version' => 'la versión de la cuenta'];
    }
}
