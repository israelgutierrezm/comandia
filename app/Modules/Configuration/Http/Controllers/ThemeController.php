<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Http\Controllers;

use App\Modules\Configuration\Application\ThemeResolver;
use App\Modules\Configuration\Infrastructure\Models\Theme;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Administración del tema por OMISIÓN del negocio (decisión de configuración, no personal).
 *
 * Es el tema que ve quien no ha elegido uno propio. Cambiarlo exige el permiso de configuración del negocio; elegir el
 * tema PROPIO no (eso vive en `PreferencesThemeController`).
 */
final class ThemeController
{
    public function __construct(private readonly ThemeResolver $resolver) {}

    public function setDefault(Theme $theme): JsonResponse
    {
        // Un solo tema por omisión: si quedaran dos, cuál gana dependería del orden de los ids. Todo dentro de una
        // transacción para que nunca haya cero o dos.
        DB::transaction(function () use ($theme): void {
            Theme::query()->where('is_default', true)->update(['is_default' => false]);
            $theme->update(['is_default' => true]);
        });

        return response()->json($this->resolver->forCurrent());
    }
}
