<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Resources;

use App\Modules\Ecommerce\Infrastructure\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Store
 */
final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'slug' => $this->slug,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'theme_primary' => $this->theme_primary,
            'public_url' => url("/t/{$this->slug}"),
            // Las sucursales que la tienda atiende, por su ULID público.
            'branch_ulids' => $this->whenLoaded(
                'storeBranches',
                fn () => $this->storeBranches->map(fn ($sb) => $sb->branch?->ulid)->filter()->values(),
            ),
        ];
    }
}
