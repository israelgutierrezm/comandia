<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Configuration\Application\ThemeResolver;
use App\Modules\Configuration\Http\Requests\SetThemeColorRequest;
use App\Modules\Configuration\Http\Requests\SetThemeRequest;
use App\Modules\Configuration\Infrastructure\Models\MembershipThemeOverride;
use App\Modules\Configuration\Infrastructure\Models\Theme;
use App\Modules\Shared\Application\Context\ContextHolder;
use Illuminate\Http\JsonResponse;

/**
 * Preferencias de apariencia de la PERSONA (estilo Acadion).
 *
 * Elegir tema y personalizar colores son decisiones individuales, no de configuración del negocio: por eso no piden
 * permiso, sólo estar autenticado. Cada acción devuelve el tema ya resuelto para que el front lo aplique sin una segunda
 * petición.
 */
final class PreferencesThemeController
{
    public function __construct(
        private readonly ThemeResolver $resolver,
        private readonly ContextHolder $context,
    ) {}

    public function setTheme(SetThemeRequest $request): JsonResponse
    {
        $theme = Theme::findByUlid($request->string('theme_ulid')->toString());

        $membership = $this->context->get()->requireMembership();
        $membership->update(['theme_id' => $theme?->id]);

        return response()->json($this->resolver->forCurrent());
    }

    public function setColor(SetThemeColorRequest $request): JsonResponse
    {
        // El tema actual tiene que admitir personalización: si no, guardar un color sería una preferencia que nunca se
        // vería, y peor —el alto contraste dejaría de garantizar su legibilidad—. Se rechaza con un motivo claro.
        if (! $this->resolver->forCurrent()['allows_override']) {
            return response()->json([
                'type' => 'unprocessable',
                'title' => 'Tu tema actual no admite ajustes de color. Elige otro tema para personalizarlo.',
                'status' => 422,
            ], 422);
        }

        $membership = $this->context->get()->requireMembership();

        MembershipThemeOverride::query()->updateOrCreate(
            ['membership_id' => $membership->id, 'token' => $request->string('token')->toString()],
            ['value' => $request->string('value')->toString()],
        );

        return response()->json($this->resolver->forCurrent());
    }

    public function clearOverrides(): JsonResponse
    {
        $membership = $this->context->get()->requireMembership();

        MembershipThemeOverride::query()->where('membership_id', $membership->id)->delete();

        return response()->json($this->resolver->forCurrent());
    }
}
