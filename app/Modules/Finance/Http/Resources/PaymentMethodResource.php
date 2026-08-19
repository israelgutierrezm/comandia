<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Resources;

use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentMethod
 */
final class PaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,

            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),

            // Las tres banderas de comportamiento. La caja las necesita todas: decide si pedir referencia, si calcular
            // cambio y si el corte debe contar este método en el efectivo esperado.
            'affects_cash_drawer' => $this->affects_cash_drawer,
            'requires_reference' => $this->requires_reference,
            'allows_change' => $this->allows_change,

            'is_system' => $this->is_system,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,

            // Qué puede hacer la interfaz con este método, resuelto en el servidor. Sin esto, la pantalla tendría que
            // saber que «de sistema» significa «se puede desactivar pero no renombrar», y esa regla acabaría escrita en
            // dos sitios que se desincronizan (D139).
            'can_be_renamed' => ! $this->is_system,
            'can_be_deleted' => ! $this->is_system,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
