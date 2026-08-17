<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'description' => $this->description,

            // El rol Propietario no es editable ni borrable (D10). Se expone para que la UI
            // deshabilite los botones en lugar de dejar que el usuario descubra el 403 al
            // pulsarlos.
            'is_system' => $this->is_system,
            'requires_two_factor' => $this->requires_two_factor,

            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->map(fn ($permission) => [
                    'name' => $permission->name,
                    'module' => $permission->module,
                    'description' => $permission->description,
                ])
                ->values()),

            'members_count' => $this->whenCounted('users'),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
