<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Infrastructure\Models\CustomerFiscalProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerFiscalProfile
 */
final class CustomerFiscalProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'rfc' => $this->rfc,
            'person_type' => $this->person_type,
            'business_name' => $this->business_name,
            'postal_code' => $this->postal_code,
            'tax_regime_code' => $this->tax_regime_code,
            'cfdi_use_code' => $this->cfdi_use_code,
            'is_default' => $this->is_default,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
