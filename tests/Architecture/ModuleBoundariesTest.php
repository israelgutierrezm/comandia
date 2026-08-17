<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * FRONTERAS DEL MONOLITO MODULAR
 *
 * Vigila las reglas de ARQUITECTURA_MAESTRA §2 que, si se erosionan, dejan de
 * tener sentido llamar "modular" al monolito:
 *
 *   1. Todo módulo que existe en disco está declarado, y todo módulo declarado
 *      existe en disco. Sin carpetas fantasma ni módulos cargados en silencio.
 *   2. El shared kernel no depende de ningún módulo de dominio.
 *   3. **Un módulo sólo referencia módulos que declaró en `depends_on`.**
 *
 * La regla 3 se agregó al aprobar P1 de la Iteración 2. Antes, el candado sólo
 * miraba al kernel: dos módulos de dominio podían acoplarse en AMBOS sentidos y
 * ninguna prueba protestaba. Con nueve iteraciones por delante, ése es el camino
 * por el que el monolito modular deja de ser modular sin que nadie tome la
 * decisión de abandonarlo.
 */

/**
 * Referencias a otros módulos que aparecen en el código de `$module`.
 *
 * @return array<string, list<string>> módulo referenciado => archivos donde aparece
 */
function referencedModules(string $module, array $allModules): array
{
    $path = app_path("Modules/{$module}");

    if (! is_dir($path)) {
        return [];
    }

    $found = [];

    foreach (Finder::create()->files()->in($path)->name('*.php') as $file) {
        $contents = (string) file_get_contents($file->getRealPath());

        foreach ($allModules as $other) {
            if ($other === $module) {
                continue;
            }

            if (str_contains($contents, "App\\Modules\\{$other}\\")) {
                $found[$other][] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getRealPath());
            }
        }
    }

    return $found;
}
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

it('todo módulo declara sus dependencias', function () {
    // Sin esta comprobación, un módulo nuevo sin `depends_on` quedaría fuera del grafo y el
    // candado de abajo lo trataría como "sin dependencias permitidas" — que es lo estricto, pero
    // por accidente y no por decisión.
    foreach (config('comandia.modules') as $name => $module) {
        expect(array_key_exists('depends_on', $module))
            ->toBeTrue("El módulo {$name} no declara `depends_on`.");

        expect($module['depends_on'])->toBeArray();
    }
});

it('un módulo sólo referencia módulos que declaró', function () {
    /** @var array<string, array<string, mixed>> $modules */
    $modules = config('comandia.modules');

    $names = array_keys($modules);
    $kernel = array_keys(array_filter($modules, fn ($m) => $m['layer'] === 'kernel'));

    $violations = [];

    foreach ($names as $module) {
        // El kernel tiene su propio candado, más estricto: no puede referenciar NINGÚN módulo
        // de dominio, ni declarándolo.
        if (in_array($module, $kernel, strict: true)) {
            continue;
        }

        $declared = $modules[$module]['depends_on'];

        foreach (referencedModules($module, $names) as $referenced => $files) {
            // Depender del kernel está permitido por la regla 1 y no se declara: listarlo en
            // cada módulo sería ruido sin información.
            if (in_array($referenced, $kernel, strict: true)) {
                continue;
            }

            if (! in_array($referenced, $declared, strict: true)) {
                $violations[] = sprintf(
                    '%s → %s (no declarado). Aparece en: %s',
                    $module,
                    $referenced,
                    implode(', ', array_slice($files, 0, 3)),
                );
            }
        }
    }

    expect($violations)->toBe([], sprintf(
        "DEPENDENCIA NO DECLARADA ENTRE MÓDULOS (ARQUITECTURA_MAESTRA §2).\n".
        "Si la dependencia es legítima, declárala en config/comandia.php; si no, el acoplamiento\n".
        "va por evento de dominio (regla 3):\n  - %s",
        implode("\n  - ", $violations),
    ));
});

it('las dependencias declaradas apuntan a módulos que existen y no son cíclicas', function () {
    /** @var array<string, array<string, mixed>> $modules */
    $modules = config('comandia.modules');

    foreach ($modules as $name => $module) {
        foreach ($module['depends_on'] as $dependency) {
            expect(array_key_exists($dependency, $modules))
                ->toBeTrue("{$name} declara depender de {$dependency}, que no existe en el registro.");

            expect($dependency)->not->toBe($name, "{$name} se declara dependiente de sí mismo.");

            // Ciclo directo. Un ciclo de tres saltos sigue siendo posible y no se vigila aquí:
            // cuando el grafo crezca lo suficiente para que eso sea plausible, se cambia por un
            // recorrido completo. Queda dicho para que nadie lo suponga cubierto.
            expect($modules[$dependency]['depends_on'] ?? [])->not->toContain(
                $name,
                "Ciclo entre {$name} y {$dependency}: las dependencias fluyen en un solo sentido."
            );
        }
    }
});

it('el candado de dependencias NO está mirando al vacío', function () {
    // Meta-verificación. El candado recorre archivos buscando referencias entre módulos; si el
    // recorrido se rompiera —una ruta mal armada, un Finder sin resultados— pasaría en verde sin
    // mirar nada, que es el modo de fallo más peligroso de un candado estructural.
    //
    // `Costing` referencia `Catalog` de verdad (la FK y el servicio de costeo), así que el
    // detector TIENE que verlo. Si esta prueba falla, el candado de arriba no está funcionando.
    $names = array_keys(config('comandia.modules'));

    expect(array_key_exists('Catalog', referencedModules('Costing', $names)))
        ->toBeTrue(
            'El detector no encontró la referencia Costing → Catalog, que sí existe: '.
            'no está leyendo el código.'
        );
});
