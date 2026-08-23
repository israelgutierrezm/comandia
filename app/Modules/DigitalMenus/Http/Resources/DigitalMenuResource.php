<?php

declare(strict_types=1);

namespace App\Modules\DigitalMenus\Http\Resources;

use App\Modules\DigitalMenus\Infrastructure\Models\DigitalMenu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DigitalMenu
 */
final class DigitalMenuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'slug' => $this->slug,
            'is_active' => $this->is_active,
            'show_prices' => $this->show_prices,
            'theme_primary' => $this->theme_primary,
            'theme_font' => $this->theme_font,
            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'name' => $this->branch->name,
            ]),
            // La URL pública, para copiar/pegar o generar el QR.
            'public_url' => url("/m/{$this->slug}"),
        ];
    }
}
