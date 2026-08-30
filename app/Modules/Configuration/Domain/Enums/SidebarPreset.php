<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Enums;

/**
 * Los colores de barra lateral que un negocio puede elegir para su panel (Apariencia).
 *
 * Paleta CURADA de tonos OSCUROS a propósito: el texto del sidebar es claro (`--color-barra-lateral-texto`), así que
 * cualquiera de estos fondos queda legible sin tocar el resto del tema. El negocio elige uno; el frontend inyecta su hex
 * en `--color-barra-lateral`. El default es la piedra, el oscuro cálido que trae el panel de fábrica.
 */
enum SidebarPreset: string
{
    case Piedra = 'piedra';
    case Grafito = 'grafito';
    case Noche = 'noche';
    case Bosque = 'bosque';
    case Vino = 'vino';
    case Indigo = 'indigo';

    /** El hex del fondo de la barra lateral. Todos son oscuros: el texto claro del sidebar se mantiene legible. */
    public function hex(): string
    {
        return match ($this) {
            self::Piedra => '#292524',
            self::Grafito => '#1f2937',
            self::Noche => '#0f172a',
            self::Bosque => '#14342b',
            self::Vino => '#3f1020',
            self::Indigo => '#1e1b4b',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Piedra => 'Piedra',
            self::Grafito => 'Grafito',
            self::Noche => 'Noche',
            self::Bosque => 'Bosque',
            self::Vino => 'Vino',
            self::Indigo => 'Índigo',
        };
    }

    /** El hex de un preset por su clave, con la piedra como refugio si la clave es desconocida. */
    public static function hexFor(string $key): string
    {
        return (self::tryFrom($key) ?? self::Piedra)->hex();
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
