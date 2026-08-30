<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application;

use App\Modules\Configuration\Infrastructure\Models\Theme;

/**
 * Siembra el catálogo de temas visuales de un negocio.
 *
 * Los seis temas son la paleta curada del producto (Océano por omisión). Los tokens usan los nombres canónicos de
 * Comandia —`contenido`/`suave` en lugar de `texto`/`texto_suave`— para que el front los mapee directo a
 * `--color-<token>` sin renombrar nada. Los colores semánticos (éxito, peligro, aviso) NO son parte del tema: quedan
 * fijos para no comprometer su legibilidad.
 *
 * Fuente ÚNICA de la definición: la usan tanto el listener de alta de negocio (Eloquent, en contexto) como la migración
 * de relleno de negocios existentes (inserción directa). Idempotente por clave de tema y por (tema, token).
 */
final class ThemeSeeder
{
    /**
     * @var list<array{clave: string, nombre: string, es_default: bool, permite_override: bool, tokens: array<string, string>}>
     */
    public const THEMES = [
        [
            // Predeterminado: azul océano sobre base neutra clara. Elegante sin cansar.
            'clave' => 'oceano',
            'nombre' => 'Océano',
            'es_default' => true,
            'permite_override' => true,
            'tokens' => [
                'barra_lateral' => '#00344D',
                'barra_lateral_suave' => '#00527C',
                'barra_lateral_texto' => '#B8DCEC',
                'barra_lateral_activo' => '#0077B6',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#0F2A3A',
                'acento' => '#006A89',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#F2F6F9',
                'superficie' => '#FFFFFF',
                'borde' => '#DCE6EC',
                'contenido' => '#0F2233',
                'suave' => '#5A7382',
            ],
        ],
        [
            'clave' => 'indigo',
            'nombre' => 'Índigo',
            'es_default' => false,
            'permite_override' => true,
            'tokens' => [
                'barra_lateral' => '#1E1B4B',
                'barra_lateral_suave' => '#312E81',
                'barra_lateral_texto' => '#C7D2FE',
                'barra_lateral_activo' => '#4F46E5',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#1E293B',
                'acento' => '#4F46E5',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#F1F5F9',
                'superficie' => '#FFFFFF',
                'borde' => '#E2E8F0',
                'contenido' => '#0F172A',
                'suave' => '#64748B',
            ],
        ],
        [
            'clave' => 'medianoche',
            'nombre' => 'Medianoche',
            'es_default' => false,
            'permite_override' => true,
            'tokens' => [
                'barra_lateral' => '#0B1120',
                'barra_lateral_suave' => '#111827',
                'barra_lateral_texto' => '#94A3B8',
                'barra_lateral_activo' => '#38BDF8',
                'barra_superior' => '#111827',
                'barra_superior_texto' => '#E2E8F0',
                'acento' => '#38BDF8',
                'acento_texto' => '#0B1120',
                'fondo' => '#0F172A',
                'superficie' => '#1E293B',
                'borde' => '#334155',
                'contenido' => '#F1F5F9',
                'suave' => '#94A3B8',
            ],
        ],
        [
            'clave' => 'esmeralda',
            'nombre' => 'Esmeralda',
            'es_default' => false,
            'permite_override' => true,
            'tokens' => [
                'barra_lateral' => '#064E3B',
                'barra_lateral_suave' => '#065F46',
                'barra_lateral_texto' => '#A7F3D0',
                'barra_lateral_activo' => '#10B981',
                'barra_superior' => '#FFFFFF',
                'barra_superior_texto' => '#1E293B',
                'acento' => '#059669',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#F0FDF4',
                'superficie' => '#FFFFFF',
                'borde' => '#D1FAE5',
                'contenido' => '#052E23',
                'suave' => '#4B7A6A',
            ],
        ],
        [
            // Rosa crema: pastel cálido y sofisticado, sin rosa fuerte ni contrastes agresivos.
            'clave' => 'rosa_crema',
            'nombre' => 'Rosa crema',
            'es_default' => false,
            'permite_override' => true,
            'tokens' => [
                'barra_lateral' => '#6F4E63',
                'barra_lateral_suave' => '#5B3F52',
                'barra_lateral_texto' => '#E7D3DA',
                'barra_lateral_activo' => '#B76E79',
                'barra_superior' => '#FFFCF9',
                'barra_superior_texto' => '#3F3438',
                'acento' => '#B76E79',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#FAF6F2',
                'superficie' => '#FFFCF9',
                'borde' => '#E8D8D2',
                'contenido' => '#3F3438',
                'suave' => '#7D6C70',
            ],
        ],
        [
            'clave' => 'alto_contraste',
            'nombre' => 'Alto contraste',
            'es_default' => false,
            // Sin overrides: personalizar colores rompería la accesibilidad que es su razón de ser.
            'permite_override' => false,
            'tokens' => [
                'barra_lateral' => '#000000',
                'barra_lateral_suave' => '#1A1A1A',
                'barra_lateral_texto' => '#FFFFFF',
                'barra_lateral_activo' => '#FFD400',
                'barra_superior' => '#000000',
                'barra_superior_texto' => '#FFFFFF',
                'acento' => '#0000CC',
                'acento_texto' => '#FFFFFF',
                'fondo' => '#FFFFFF',
                'superficie' => '#FFFFFF',
                'borde' => '#000000',
                'contenido' => '#000000',
                'suave' => '#333333',
            ],
        ],
    ];

    /**
     * Siembra los temas del negocio en contexto (lo usa el listener de alta). El `tenant_id` lo pone el scope.
     */
    public function seed(): void
    {
        foreach (self::THEMES as $datos) {
            $theme = Theme::query()->updateOrCreate(
                ['key' => $datos['clave']],
                [
                    'name' => $datos['nombre'],
                    'is_default' => $datos['es_default'],
                    'allows_user_override' => $datos['permite_override'],
                ],
            );

            foreach ($datos['tokens'] as $token => $valor) {
                $theme->tokens()->updateOrCreate(['token' => $token], ['value' => $valor]);
            }
        }
    }
}
