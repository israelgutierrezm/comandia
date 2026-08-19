<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CANDADO: dos archivos de prueba no pueden declarar el mismo ayudante global.
 *
 * ## Por qué hace falta
 *
 * Los ayudantes de un archivo de Pest son **funciones globales de PHP**. Dos archivos que declaren `saldo()` no
 * producen una prueba en rojo: producen un `Fatal error: Cannot redeclare` que aborta **la suite completa** antes de
 * ejecutar nada, así que no hay resultado que leer y no se sabe qué se rompió.
 *
 * Y lo peor es cómo se esconde: correr el archivo solo pasa en verde. El fallo aparece únicamente cuando los dos
 * archivos entran en la misma corrida, que es lo que pasa al final de una entrega — justo cuando ya se dio por bueno.
 *
 * Ocurrió en el paso 6 de la Iteración 3: `TransferTest` declaró `saldo()` y `StockMovementTest` ya lo tenía. La
 * suite del paso pasaba en verde; la suite completa no arrancaba.
 *
 * ## Por qué un candado y no «acordarse»
 *
 * Porque los nombres naturales en español se repiten: `saldo`, `merma`, `surte`, `existencia`. Cuantos más módulos,
 * más colisiones, y la señal que da el fallo —una suite que no arranca— es la más difícil de diagnosticar de todas.
 */
it('ningún ayudante de prueba está declarado dos veces', function () {
    $declarations = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
        $contents = $file->getContents();

        // Sólo funciones a nivel de archivo: `^function` sin indentación. Los métodos de clase y los closures no
        // colisionan, y contarlos daría falsos positivos.
        preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $contents, $matches);

        foreach ($matches[1] as $name) {
            $declarations[$name][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    $duplicated = array_filter($declarations, fn (array $files): bool => count($files) > 1);

    $mensaje = '';

    foreach ($duplicated as $name => $files) {
        $mensaje .= sprintf("\n - %s() en:\n   %s", $name, implode("\n   ", $files));
    }

    expect($duplicated)->toBe(
        [],
        "Estos ayudantes de prueba están declarados en más de un archivo:{$mensaje}\n\n".
        'Los ayudantes de Pest son funciones GLOBALES: dos declaraciones del mismo nombre abortan la suite completa '.
        "con «Cannot redeclare» antes de ejecutar nada.\n".
        'Ponles un nombre propio del módulo (`saldoEnAlmacen`, no `saldo`) o súbelos a `tests/Pest.php` una sola vez.',
    );
});

it('el candado se mira a sí mismo', function () {
    // Meta-verificación: si el recolector dejara de encontrar declaraciones —un cambio de expresión regular, una
    // carpeta movida— la prueba de arriba pasaría en verde sin mirar nada, que es el peor resultado posible para un
    // candado. Aquí se comprueba que sí ve.
    $encontrados = 0;

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
        preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $file->getContents(), $matches);
        $encontrados += count($matches[1]);
    }

    expect($encontrados)->toBeGreaterThan(
        10,
        'El candado no encontró apenas ayudantes: la búsqueda dejó de funcionar y arriba no está mirando nada.',
    );
});
