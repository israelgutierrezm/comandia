<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Los acentos de marca que un negocio puede elegir para su panel (rediseño, Fase B).
 *
 * Una paleta CURADA en vez de un selector de color libre: cada preset es un tono lo bastante oscuro para llevar texto
 * blanco encima, así el panel siempre se ve bien y nadie termina con un acento ilegible. El negocio elige uno; el frontend
 * inyecta su hex en `--color-acento` en runtime. El default es la terracota, la identidad cálida de alimentos y bebidas.
 */
enum AccentPreset: string
{
    case Terracota = 'terracota';
    case Esmeralda = 'esmeralda';
    case Oceano = 'oceano';
    case Ciruela = 'ciruela';
    case Vino = 'vino';
    case Pizarra = 'pizarra';

    /** El hex del acento. Todos son oscuros: el texto encima es blanco (el default del tema). */
    public function hex(): string
    {
        return match ($this) {
            self::Terracota => '#c2410c',
            self::Esmeralda => '#047857',
            self::Oceano => '#0369a1',
            self::Ciruela => '#7c3aed',
            self::Vino => '#9f1239',
            self::Pizarra => '#334155',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Terracota => 'Terracota',
            self::Esmeralda => 'Esmeralda',
            self::Oceano => 'Océano',
            self::Ciruela => 'Ciruela',
            self::Vino => 'Vino',
            self::Pizarra => 'Pizarra',
        };
    }

    /**
     * El hex de un preset por su clave, con la terracota como refugio si la clave es desconocida.
     */
    public static function hexFor(string $key): string
    {
        return (self::tryFrom($key) ?? self::Terracota)->hex();
    }

    /**
     * @return list<string> las claves, para el catálogo de ajustes
     */
    public static function keys(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }

    /**
     * @return array<string, string> clave => etiqueta, para la lista blanca con nombres
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $preset) {
            $labels[$preset->value] = $preset->label();
        }

        return $labels;
    }
}
