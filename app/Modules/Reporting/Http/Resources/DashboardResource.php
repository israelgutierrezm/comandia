<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Reporting\Infrastructure\Models\Dashboard;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Dashboard
 */
final class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $membership = app(ContextHolder::class)->get()->membership;

        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            // Sólo el autor puede editar; la pantalla usa esto para mostrar u ocultar los controles.
            'is_mine' => $membership !== null && (int) $this->membership_id === (int) $membership->id,
            'published_role_ulid' => $this->published_role_id === null
                ? null
                : Role::query()->whereKey($this->published_role_id)->value('ulid'),
            'widgets' => DashboardWidgetResource::collection(
                $this->whenLoaded('widgets', fn () => $this->widgets->sortBy('position')->values()),
            ),
        ];
    }
}
