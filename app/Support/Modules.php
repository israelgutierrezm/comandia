<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lectura del registro declarativo de módulos de `config/comandia.php` (ARQUITECTURA_MAESTRA §2,
 * D64).
 *
 * El registro ya se consultaba desde varios lugares con `config('comandia.modules')` a pelo. Esta
 * clase existe para lo que NO conviene repetir: la etiqueta en español del módulo, que es la que
 * termina en pantalla. Un `?? $module` disperso en tres controladores acaba pintando `Costing` en
 * alguno de ellos.
 */
final class Modules
{
    /**
     * @return array<string, array{layer: string, activatable: bool, iteration: int, label: string}>
     */
    public static function all(): array
    {
        /** @var array<string, array{layer: string, activatable: bool, iteration: int, label: string}> */
        return (array) config('comandia.modules', []);
    }

    /**
     * Nombre del módulo en español, para la interfaz.
     *
     * Cae al identificador si el módulo no está en el registro, y eso es intencional: un módulo sin
     * registrar es un error de programación, y ver `Costing` en la pantalla es una señal mucho más
     * útil que una cadena vacía o una excepción en producción. El candado que impide que ocurra es
     * `tests/Architecture/ModuleRegistryTest.php`.
     */
    public static function label(string $module): string
    {
        $registry = self::all();

        $label = $registry[$module]['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $module;
    }

    /**
     * Etiquetas de los módulos indicados, listas para acompañar una respuesta agrupada por módulo.
     *
     * @param  iterable<string>  $modules
     * @return array<string, string>
     */
    public static function labels(iterable $modules): array
    {
        $labels = [];

        foreach ($modules as $module) {
            $labels[$module] = self::label($module);
        }

        return $labels;
    }
}
