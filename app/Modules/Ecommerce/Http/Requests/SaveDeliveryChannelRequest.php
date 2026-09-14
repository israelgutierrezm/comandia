<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Requests;

use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Configurar un canal de marketplace por sucursal (ADR-015). El permiso lo gatea la ruta
 * (`ecommerce.store.configure`); aquí sólo se validan los datos.
 */
final class SaveDeliveryChannelRequest extends FormRequest
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
            'branch_ulid' => ['required', 'string'],
            'channel' => ['required', Rule::enum(DeliveryChannelType::class)],
            'is_active' => ['required', 'boolean'],
            'external_store_id' => ['nullable', 'string', 'max:120'],
            // Tasa de comisión en % (0–100). El diario la aplica como resta al netear la venta.
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
