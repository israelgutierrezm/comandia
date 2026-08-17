<?php

declare(strict_types=1);

use App\Support\Queue;

/**
 * El registro de módulos y el catálogo de colas son configuración con
 * consecuencias: un módulo mal declarado no carga sus rutas ni sus migraciones,
 * y una cola mal escrita produce jobs que nadie procesa.
 */
it('declara los módulos del shared kernel', function () {
    $modules = config('comandia.modules');

    $kernel = array_keys(array_filter($modules, fn ($m) => $m['layer'] === 'kernel'));

    expect($kernel)->toEqualCanonicalizing([
        'Shared',
        'Tenancy',
        'Identity',
        'Organization',
        'Configuration',
        'Audit',
        'Notifications',
    ]);
});

it('marca como activables sólo los módulos opcionales del catálogo comercial', function () {
    $modules = config('comandia.modules');

    $activatable = array_keys(array_filter($modules, fn ($m) => $m['activatable'] === true));

    // ESPECIFICACION_MAESTRA §5: sólo menús digitales y e-commerce son
    // activables. Administración y POS son producto núcleo, siempre activos.
    expect($activatable)->toEqualCanonicalizing(['DigitalMenus', 'Ecommerce']);
});

it('cada módulo declara su etiqueta en español para la interfaz', function () {
    // La pantalla de configuración y el editor de roles se autoconfiguran desde la API (ADR-006):
    // agrupan por módulo lo que la API les manda. Sin etiqueta, la agrupación se pinta con el
    // identificador —`Costing`, `Pos`— y la UI queda en inglés, contra la regla de idioma de
    // CLAUDE.md. Pasó: la configuración mostraba «CONFIGURATION» y «COSTING» como encabezados.
    foreach (config('comandia.modules') as $name => $module) {
        expect(array_key_exists('label', $module))
            ->toBeTrue("El módulo {$name} no declara `label`.");

        expect($module['label'])->toBeString()->not->toBe('');

        // El identificador es inglés porque es código; la etiqueta existe justamente para no
        // mostrarlo. Si son iguales, la etiqueta no está haciendo su trabajo.
        expect($module['label'])->not->toBe($name, "La etiqueta de {$name} es el identificador.");
    }
});

it('el catálogo de colas es cerrado', function () {
    expect(array_map(fn (Queue $q) => $q->value, Queue::cases()))
        ->toEqualCanonicalizing(['critical', 'default', 'exports', 'printing']);
});

it('la cola crítica se drena antes que las demás', function () {
    expect(Queue::workerOrder()[0])->toBe(Queue::Critical->value);
});
