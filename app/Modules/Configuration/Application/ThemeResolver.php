<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Models\MembershipThemeOverride;
use App\Modules\Configuration\Infrastructure\Models\Theme;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Shared\Application\Context\ContextHolder;

/**
 * Resuelve qué colores ve la persona que tiene el panel abierto, en cascada:
 *
 *     tema por omisión del negocio → tema que eligió la membresía → sus overrides personales
 *
 * (los overrides sólo si ese tema los permite). Devuelve los tokens ya combinados para que el front sólo los inyecte
 * como CSS custom properties, sin conocer la paleta.
 *
 * Sin contexto —la pantalla de acceso, antes de elegir negocio— devuelve tokens vacíos y el front cae en los `:root` por
 * omisión de la hoja de estilos. Es kernel dependiendo de kernel (Identity), permitido por §2.
 */
final readonly class ThemeResolver
{
    public function __construct(private ContextHolder $context) {}

    /**
     * @return array{key: string|null, name: string|null, tokens: array<string, string>, allows_override: bool, available: list<array<string, mixed>>}
     */
    public function forCurrent(): array
    {
        if (! $this->context->has()) {
            return $this->empty();
        }

        $membership = $this->context->get()->membership;
        $theme = $this->chosen($membership);

        if ($theme === null) {
            return $this->empty();
        }

        /** @var array<string, string> $tokens */
        $tokens = $theme->tokens->pluck('value', 'token')->all();

        if ($membership !== null && $theme->allows_user_override) {
            $tokens = [...$tokens, ...$this->overrides($membership)];
        }

        return [
            'key' => $theme->key,
            'name' => $theme->name,
            'tokens' => $tokens,
            'allows_override' => (bool) $theme->allows_user_override,
            'available' => $this->available(),
        ];
    }

    /**
     * El tema de la membresía si eligió uno y sigue existiendo; si no, el que el negocio marcó por omisión.
     */
    private function chosen(?TenantMembership $membership): ?Theme
    {
        if ($membership?->theme_id !== null) {
            $theme = Theme::query()->with('tokens')->find($membership->theme_id);

            if ($theme !== null) {
                return $theme;
            }
        }

        return Theme::query()->with('tokens')->where('is_default', true)->first()
            ?? Theme::query()->with('tokens')->first();
    }

    /**
     * @return array<string, string>
     */
    private function overrides(TenantMembership $membership): array
    {
        /** @var array<string, string> */
        return MembershipThemeOverride::query()
            ->where('membership_id', $membership->id)
            ->pluck('value', 'token')
            ->all();
    }

    /**
     * Todos los temas del negocio, para que el selector no requiera otra petición. Con una muestra de tres colores para
     * pintar la miniatura.
     *
     * @return list<array<string, mixed>>
     */
    private function available(): array
    {
        return Theme::query()
            ->with('tokens')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Theme $theme): array => [
                'ulid' => $theme->ulid,
                'key' => $theme->key,
                'name' => $theme->name,
                'is_default' => (bool) $theme->is_default,
                'sample' => [
                    'barra_lateral' => $theme->tokens->firstWhere('token', 'barra_lateral')?->value,
                    'acento' => $theme->tokens->firstWhere('token', 'acento')?->value,
                    'fondo' => $theme->tokens->firstWhere('token', 'fondo')?->value,
                ],
            ])
            ->all();
    }

    /**
     * @return array{key: null, name: null, tokens: array<string, string>, allows_override: false, available: list<array<string, mixed>>}
     */
    private function empty(): array
    {
        return ['key' => null, 'name' => null, 'tokens' => [], 'allows_override' => false, 'available' => []];
    }
}
