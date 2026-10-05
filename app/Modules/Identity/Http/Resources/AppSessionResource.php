<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\AppSessions;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una sesión de la app: el dispositivo, el negocio, cuándo entró y cuándo se usó por última vez.
 *
 * Nunca sale el token ni su id: sólo el ULID con el que se cierra. `closes_idle_at` es cuándo se cerrará sola si nadie
 * la usa (60 días desde el último uso), para que la pantalla lo pueda decir. `is_current` marca la sesión con la que se
 * hizo esta misma petición, que sólo existe si se pidió desde la app.
 *
 * @mixin PersonalAccessToken
 */
final class AppSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ultimoUso = $this->last_used_at ?? $this->created_at;

        // El operador de una terminal compartida no es un `User` y no tiene token propio (ADR-012).
        $quien = $request->user();
        $actual = $quien !== null && method_exists($quien, 'currentAccessToken') ? $quien->currentAccessToken() : null;

        return [
            'ulid' => $this->ulid,
            'device_name' => $this->name,
            'business' => $this->relationLoaded('tenant') && $this->tenant !== null
                ? ['ulid' => $this->tenant->ulid, 'name' => $this->tenant->name]
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'closes_idle_at' => $ultimoUso?->copy()->addDays(AppSessions::IDLE_DAYS)->toIso8601String(),
            'is_current' => $actual instanceof PersonalAccessToken && (int) $actual->getKey() === (int) $this->id,
        ];
    }
}
