<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
final class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'value' => $this->value,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'max_uses' => $this->max_uses,
            'uses_count' => $this->uses_count,
            'per_customer_limit' => $this->per_customer_limit,
            'is_active' => $this->is_active,
        ];
    }
}
