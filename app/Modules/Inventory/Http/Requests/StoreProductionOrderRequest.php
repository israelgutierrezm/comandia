<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una orden de producción (D17, P8).
 *
 * No lleva renglones, y ésa es la diferencia con una transferencia: **lo que se consume lo dice la receta**, no quien
 * captura. Dejar que el cliente mandara los insumos permitiría producir salsa consumiendo cualquier cosa, y la receta
 * dejaría de significar algo.
 *
 * Que el artículo sea producible y tenga receta activa NO se valida aquí: son invariantes del catálogo que el servicio
 * comprueba, y sus mensajes explican cómo arreglarlo. Duplicarlos en el Form Request produciría dos textos que se
 * desincronizan.
 */
final class StoreProductionOrderRequest extends FormRequest
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
            'warehouse_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('warehouses', 'ulid')
                    ->where('tenant_id', $tenantId)
                    // El almacén de tránsito no produce nada: lo escriben sólo las transferencias (D190).
                    ->whereNot('kind', 'transit'),
            ],

            'article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_producible', true),
            ],

            // En la unidad BASE del producible, como todo el kardex. Mayor que cero: producir cero no es producir, y
            // además lo prohíbe un CHECK en la tabla.
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'article_ulid.exists' => 'Ese artículo no existe o no está marcado como producible.',
            'planned_quantity.gt' => 'La cantidad a producir tiene que ser mayor que cero.',
            'planned_quantity.decimal' => 'La cantidad admite hasta cuatro decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_ulid' => 'el almacén',
            'article_ulid' => 'el artículo',
            'planned_quantity' => 'la cantidad a producir',
            'notes' => 'las notas',
        ];
    }
}
