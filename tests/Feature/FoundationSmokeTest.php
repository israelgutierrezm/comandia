<?php

declare(strict_types=1);

/**
 * Humo de la Fase 0: la aplicación arranca, el shell de Inertia responde, la API
 * versionada existe y el chequeo de salud contesta.
 *
 * No prueba nada de negocio a propósito: en Fase 0 no hay negocio.
 */
it('sirve el shell de la aplicación', function () {
    // withoutVite: este test verifica ruteo y el shell de Inertia, no el
    // empaquetado. Depender del manifest compilado haría que la suite fallara en
    // un clon recién hecho por una razón que no tiene que ver con el código.
    // La compilación se verifica con `npm run build` en el pipeline.
    $this->withoutVite()
        ->get('/')
        ->assertOk()
        ->assertSee('Comandia', escape: false);
});

it('expone la API versionada en /api/v1', function () {
    $this->getJson('/api/v1')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.name', 'Comandia');
});

it('responde el chequeo de salud', function () {
    $this->get('/up')->assertOk();
});

it('opera sobre MySQL con InnoDB forzado', function () {
    // El MySQL de desarrollo tiene default_storage_engine = MyISAM. Si alguien
    // quita `'engine' => 'InnoDB'` de config/database.php, las tablas se crearían
    // sin FKs ni transacciones y el proyecto perdería su integridad referencial
    // sin un solo error visible. Este test es el candado.
    expect(config('database.default'))->toBe('mysql');
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB');

    $table = collect(DB::select('SHOW TABLE STATUS WHERE Name = ?', ['migrations']))->first();

    expect($table)->not->toBeNull('La tabla `migrations` no existe en la base de pruebas.');
    expect($table->Engine)->toBe('InnoDB');
});

it('declara las cuatro colas del proyecto en el orden de prioridad correcto', function () {
    expect(config('comandia.queues'))->toBe(['critical', 'default', 'exports', 'printing']);
});

it('configura Spatie con teams = tenant', function () {
    expect(config('permission.teams'))->toBeTrue();
    expect(config('permission.column_names.team_foreign_key'))->toBe('tenant_id');
});
