<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Humo de la Fase 0: la aplicación arranca, el shell de Inertia responde, la API
 * versionada existe y el chequeo de salud contesta.
 *
 * No prueba nada de negocio a propósito: en Fase 0 no hay negocio.
 */
it('la raíz reparte según haya sesión, sin pantalla propia', function () {
    // Antes renderizaba la página `Welcome` de la Fase 0, eliminada al construir la UI de
    // administración sin actualizar la ruta. El test siguió pasando —el shell responde 200— y en el
    // navegador la primera pantalla del proyecto era una excepción de JavaScript. La lección: un 200
    // del shell NO prueba que la página exista.
    $this->withoutVite()->get('/')->assertRedirect('/login');
});

it('sólo enruta páginas de Inertia que existen como componente', function () {
    // El candado que faltaba. Recorre las rutas web que renderizan Inertia y exige el .vue
    // correspondiente en disco. Sin esto, cualquier pantalla nueva puede quedar rota en el
    // navegador con la suite entera en verde, porque el shell responde 200 igual.
    $paginas = [];

    foreach (Route::getRoutes() as $ruta) {
        $accion = $ruta->getActionName();

        if ($accion !== 'Closure' || ! in_array('GET', $ruta->methods(), strict: true)) {
            continue;
        }

        $codigo = new ReflectionFunction($ruta->getAction('uses'));
        $archivo = file($codigo->getFileName());
        $cuerpo = implode('', array_slice(
            $archivo,
            $codigo->getStartLine() - 1,
            $codigo->getEndLine() - $codigo->getStartLine() + 1,
        ));

        if (preg_match("/Inertia::render\(\s*'([^']+)'/", $cuerpo, $coincidencia) === 1) {
            $paginas[] = $coincidencia[1];
        }
    }

    expect($paginas)->not->toBeEmpty('No se encontró ninguna ruta que renderice Inertia.');

    foreach ($paginas as $pagina) {
        expect(resource_path("js/Pages/{$pagina}.vue"))
            ->toBeFile("La ruta renderiza `{$pagina}` pero no existe resources/js/Pages/{$pagina}.vue");
    }
});

it('declara el host de APP_URL entre los dominios con sesión de Sanctum', function () {
    // La SPA se autentica por cookie. Si el host que sirve la aplicación no está en
    // `sanctum.stateful`, la sesión funciona, el shell carga y TODA /api/v1 responde 401 — un modo
    // de falla que no se parece a un problema de configuración. Pasó sirviendo en otro puerto.
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    $puerto = parse_url((string) config('app.url'), PHP_URL_PORT);

    $esperado = $puerto === null ? $host : "{$host}:{$puerto}";

    expect(config('sanctum.stateful'))->toContain($esperado);
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
