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

it('el catálogo de colas es cerrado', function () {
    expect(array_map(fn (Queue $q) => $q->value, Queue::cases()))
        ->toEqualCanonicalizing(['critical', 'default', 'exports', 'printing']);
});

it('la cola crítica se drena antes que las demás', function () {
    expect(Queue::workerOrder()[0])->toBe(Queue::Critical->value);
});
