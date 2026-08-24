<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentGatewaySetting
 *
 * Nunca devuelve las claves secretas —sólo si están puestas—, igual que la configuración de correo (It.7).
 */
final class PaymentGatewaySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'active_gateway' => $this->active_gateway,
            'public_key' => $this->public_key,
            'has_secret_key' => $this->secret_key !== null,
            'has_webhook_secret' => $this->webhook_secret !== null,
        ];
    }
}
