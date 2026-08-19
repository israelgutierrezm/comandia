<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registro de una merma (D27, §6.2).
 *
 * El **motivo es obligatorio**, y es la diferencia entre una merma y una salida manual: una merma sin motivo es una
 * salida que nadie puede explicar, y el reporte agrupado por motivo que D27 hace posible se quedaría sin agrupador.
 *
 * La cantidad no se limita por lo disponible: §6.2 permite existencias negativas y una merma de lo que el sistema no
 * sabía que había es información legítima — casi siempre significa que el conteo va atrasado.
 */
final class StoreWasteRequest extends FormRequest
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
                Rule::exists('warehouses', 'ulid')->where('tenant_id', $tenantId),
            ],

            'article_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('articles', 'ulid')->where('tenant_id', $tenantId),
            ],

            'waste_reason_ulid' => [
                'required', 'string', 'size:26',
                Rule::exists('waste_reasons', 'ulid')->where('tenant_id', $tenantId),
            ],

            'lot_ulid' => [
                'nullable', 'string', 'size:26',
                Rule::exists('article_lots', 'ulid')->where('tenant_id', $tenantId),
            ],

            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999.9999', 'decimal:0,4'],

            // La concesión de PIN. Se manda sólo cuando el negocio la exige por monto, y el servidor decide si hace
            // falta: el cliente no puede saberlo sin valuar la merma, que es trabajo del servidor.
            'authorization_token' => ['nullable', 'string', 'max:120'],

            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:200'],
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

                $reason = WasteReason::query()->where('ulid', $this->string('waste_reason_ulid'))->first();

                // Un motivo dado de baja sigue existiendo —los movimientos que lo citan lo necesitan— pero no se
                // puede seguir usando. Sin esta comprobación, un cliente con un selector en caché seguiría
                // capturando mermas con un motivo que el negocio ya retiró.
                if ($reason !== null && ! $reason->isActive()) {
                    $validator->errors()->add(
                        'waste_reason_ulid',
                        "El motivo «{$reason->name}» está dado de baja. Elige otro."
                    );
                }

                if (! $this->filled('lot_ulid')) {
                    return;
                }

                $lotBelongs = ArticleLot::query()
                    ->where('ulid', $this->string('lot_ulid')->toString())
                    ->whereHas('article', fn ($q) => $q->where('ulid', $this->string('article_ulid')->toString()))
                    ->exists();

                if (! $lotBelongs) {
                    $validator->errors()->add(
                        'lot_ulid',
                        'Ese lote no es de este artículo. Mezclarlos sumaría dos existencias distintas bajo el '.
                        'mismo saldo.'
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
            'waste_reason_ulid.required' => 'Elige el motivo de la merma: sin motivo, la pérdida no se puede '.
                'investigar ni agrupar en un reporte.',
            'waste_reason_ulid.exists' => 'Ese motivo de merma no existe.',
            'quantity.gt' => 'La cantidad de la merma tiene que ser mayor que cero.',
            'quantity.decimal' => 'La cantidad admite hasta cuatro decimales.',
            'occurred_at.before_or_equal' => 'La fecha no puede estar en el futuro.',
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
            'waste_reason_ulid' => 'el motivo',
            'lot_ulid' => 'el lote',
            'quantity' => 'la cantidad',
            'authorization_token' => 'la autorización',
            'notes' => 'las notas',
        ];
    }
}
