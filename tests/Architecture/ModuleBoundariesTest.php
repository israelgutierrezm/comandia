<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * FRONTERAS DEL MONOLITO MODULAR
 *
 * Vigila las dos reglas de ARQUITECTURA_MAESTRA §2 que, si se erosionan, dejan
 * de tener sentido llamar "modular" al monolito:
 *
 *   1. Todo módulo que existe en disco está declarado, y todo módulo declarado
 *      existe en disco. Sin carpetas fantasma ni módulos cargados en silencio.
 *   2. El shared kernel no depende de ningún módulo de dominio.
 *
 * En Fase 0 la regla 2 recorre carpetas sin código: pasa trivialmente y empieza
 * a morder en la Iteración 1.
 */
it('el registro de módulos y las carpetas en disco coinciden', function () {
    /** @var array<string, array<string, mixed>> $declared */
    $declared = config('comandia.modules');

    $onDisk = collect(Finder::create()->directories()->depth(0)->in(app_path('Modules')))
        ->map(fn ($dir) => $dir->getFilename())
        ->values()
        ->all();

    expect(array_diff($onDisk, array_keys($declared)))
        ->toBe([], 'Hay carpetas en app/Modules que no están declaradas en config/comandia.php.');

    expect(array_diff(array_keys($declared), $onDisk))
        ->toBe([], 'Hay módulos declarados en config/comandia.php que no existen en app/Modules.');
});

it('el shared kernel no depende de ningún módulo de dominio', function () {
    /** @var array<string, array<string, mixed>> $modules */
    $modules = config('comandia.modules');

    $kernel = array_keys(array_filter($modules, fn ($m) => $m['layer'] === 'kernel'));
    $nonKernel = array_keys(array_filter($modules, fn ($m) => $m['layer'] !== 'kernel'));

    $violations = [];

    foreach ($kernel as $module) {
        $path = app_path("Modules/{$module}");

        if (! is_dir($path)) {
            continue;
        }

        foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
            $contents = (string) file_get_contents($file->getRealPath());

            foreach ($nonKernel as $forbidden) {
                if (str_contains($contents, "App\\Modules\\{$forbidden}\\")) {
                    $violations[] = sprintf(
                        '%s referencia App\Modules\%s',
                        str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath()),
                        $forbidden,
                    );
                }
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "El shared kernel no puede depender de módulos de dominio (ARQUITECTURA_MAESTRA §2, regla 1):\n  - %s",
        implode("\n  - ", $violations),
    ));
});
