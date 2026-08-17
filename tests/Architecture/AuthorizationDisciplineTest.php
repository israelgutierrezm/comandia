<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Authorization\Authorize;
use Symfony\Component\Finder\Finder;
use Tests\Support\SourceScanner;

/**
 * CANDADOS DE LA DISCIPLINA DE AUTORIZACIÓN
 *
 * Tres reglas del proyecto que sólo viven en la revisión de código se erosionan. Estas
 * pruebas las convierten en estructura:
 *
 *   1. D9 — la verificación pasa por el rol activo, nunca por la suma de roles.
 *      Prohibido `$user->can()`, `Gate::allows`, `@can`, `hasPermissionTo`.
 *   2. ADR-008 — la excepción de la unión de roles vive en UN solo servicio.
 *   3. ADR-002 — `withoutGlobalScopes()` sólo donde está justificado.
 *
 * Cada una lleva su lista de excepciones con la razón escrita, y una prueba que falla
 * si la lista crece sin que alguien lo decida.
 */

/*
|--------------------------------------------------------------------------
| Regla 1 — Prohibido evaluar la suma de roles (D9)
|--------------------------------------------------------------------------
|
| Spatie suma los permisos de todos los roles del usuario en el tenant. Comandia
| opera bajo un único ROL ACTIVO: un mesero que además es gerente y está
| operando como mesero no debe poder cancelar un platillo comandado.
|
| El único camino permitido es App\Modules\Shared\Application\Authorization\Authorize.
|
*/
$apiProhibida = [
    '->can(',
    'Gate::allows',
    'Gate::denies',
    'Gate::authorize',
    'hasPermissionTo(',
    'hasAnyPermission(',
    'hasRole(',
    'hasAnyRole(',
];

/**
 * Archivos autorizados a usar la API de Spatie, con su razón.
 *
 * @var array<string, string>
 */
$excepciones = [
    // El servicio de autorización ES la implementación de la regla: alguien tiene que
    // leer los permisos del rol.
    //
    // Nota: su método público se llama `allows()` y no `can()` precisamente para que los
    // archivos que lo USAN no necesiten estar en esta lista. La lista es corta porque la API
    // correcta es textualmente distinta de la prohibida, no porque nadie autorice nada.
    'app/Modules/Shared/Application/Authorization/Authorize.php' => 'implementa la verificación por rol activo',

    // El modelo de rol expone los permisos de UN rol. La relación de Spatie es la vía
    // legítima para eso; lo prohibido es preguntarle al usuario.
    'app/Modules/Identity/Infrastructure/Models/Role.php' => 'expone los permisos de un solo rol',

    // El middleware valida que el rol pedido por header esté asignado, consultando el
    // pivote. No autoriza nada: sólo comprueba pertenencia.
    'app/Modules/Shared/Http/Middleware/ResolveTenantContext.php' => 'valida pertenencia del rol pedido por header',
];

it('nadie evalúa la suma de roles fuera del servicio de autorización', function () use ($apiProhibida, $excepciones) {
    $infracciones = SourceScanner::findUsages($apiProhibida, array_keys($excepciones));

    expect($infracciones)->toBe([], sprintf(
        "Estos archivos evalúan permisos por fuera del servicio de autorización (D9):\n  - %s\n\n".
        "Spatie SUMA los roles; Comandia opera bajo el rol activo. Usa\n".
        'App\Modules\Shared\Application\Authorization\Authorize::can() o ::authorize().',
        implode("\n  - ", $infracciones),
    ));
});

it('la lista de excepciones a D9 no crece sin decisión', function () use ($excepciones) {
    expect($excepciones)->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Regla 2 — La excepción de ADR-008 está acotada
|--------------------------------------------------------------------------
|
| La autorización por PIN evalúa la UNIÓN de los roles del autorizador, porque
| quien teclea su PIN no tiene sesión y por tanto no tiene rol activo. Es una
| excepción aprobada a D9 y vive en UN solo servicio. Sin este candado, la
| excepción se vuelve la regla por goteo.
|
*/
/**
 * Patrones que resuelven permisos a partir de MÁS DE UN ROL a la vez.
 *
 * La lista incluye la forma concreta que usa la implementación
 * (`roles()->whereHas('permissions'`) y no sólo la API de Spatie: un candado que vigila
 * patrones que nadie usa no vigila nada, y el test de más abajo se encarga de que eso no
 * pase inadvertido.
 *
 * @var list<string>
 */
$unionDeRoles = [
    'getAllPermissions(',
    'getPermissionsViaRoles(',
    'permissionsViaRoles',
    "roles()->whereHas('permissions'",
];

$servicioPin = 'app/Modules/Identity/Application/PinAuthorization/PinAuthorizationService.php';

it('la unión de roles sólo se consulta en la autorización por PIN', function () use ($unionDeRoles, $servicioPin) {
    $infracciones = SourceScanner::findUsages($unionDeRoles, [$servicioPin]);

    expect($infracciones)->toBe([], sprintf(
        "La unión de roles sólo puede consultarse en el servicio de autorización por PIN\n".
        "(ADR-008): en todo lo demás opera el rol activo (D9). Aparece en:\n  - %s",
        implode("\n  - ", $infracciones),
    ));
});

it('el candado de ADR-008 vigila algo de verdad', function () use ($unionDeRoles, $servicioPin) {
    // META-VERIFICACIÓN. El candado de arriba sólo tiene sentido si sus patrones describen
    // cómo se implementa realmente la excepción. Si alguien reescribe el servicio de PIN con
    // otra forma de consultar la unión de roles, el candado seguiría verde mientras dejaría
    // de proteger nada — y ésa es la peor forma de tener un candado.
    //
    // Este test falla en ese caso y obliga a actualizar la lista de patrones.
    // Se normaliza igual que el escáner, porque el formateador parte las cadenas de llamadas
    // en varias líneas y el patrón tiene que coincidir con el código real, no con su formato.
    $contenido = SourceScanner::normalize((string) file_get_contents(base_path($servicioPin)));

    $encontrado = array_filter(
        $unionDeRoles,
        fn (string $patron): bool => str_contains($contenido, $patron),
    );

    expect($encontrado)->not->toBe([], sprintf(
        "Ninguno de los patrones vigilados aparece en %s.\n\n".
        "O la excepción de ADR-008 ya no se implementa consultando la unión de roles —y hay\n".
        "que revisar la ADR—, o se reescribió con otra forma y la lista de patrones de este\n".
        'archivo quedó obsoleta, con lo que el candado dejó de proteger nada.',
        $servicioPin,
    ));
});

/*
|--------------------------------------------------------------------------
| Regla 3 — `withoutGlobalScopes()` restringido (ADR-002)
|--------------------------------------------------------------------------
*/
$excepcionesScope = [
    // Flujo de identidad: "¿a qué tenants pertenece este correo?" ocurre ANTES de que
    // exista contexto, así que no puede estar acotada. Vive en identidad, no en código
    // de dominio, así que no viola la Regla B.
    'app/Modules/Identity/Infrastructure/Models/User.php' => 'selector de tenant en el login, antes de que exista contexto',
];

it('withoutGlobalScopes sólo se usa donde está justificado', function () use ($excepcionesScope) {
    $infracciones = SourceScanner::findUsages(
        ['withoutGlobalScopes(', 'withoutGlobalScope('],
        array_keys($excepcionesScope),
    );

    expect($infracciones)->toBe([], sprintf(
        "Quitar el global scope de tenant deja una consulta cross-tenant (ADR-002, Regla B).\n".
        "Sólo el módulo de super admin y el flujo de identidad pueden hacerlo, con su razón\n".
        "escrita en tests/Architecture/AuthorizationDisciplineTest.php. Aparece en:\n  - %s",
        implode("\n  - ", $infracciones),
    ));
});

/*
|--------------------------------------------------------------------------
| Regla 4 — `model_has_permissions` debe permanecer vacía (D10)
|--------------------------------------------------------------------------
*/
it('nadie asigna permisos directos a un usuario', function () {
    // El tenant combina permisos en roles, no asigna permisos directos. Un permiso
    // directo sería invisible para el concepto de rol activo y rompería D9 en silencio.
    $infracciones = SourceScanner::findUsages(
        ['givePermissionTo(', 'syncPermissions(', 'revokePermissionTo('],
        [
            // Los ROLES sí reciben permisos: es el mecanismo previsto por D10, y este
            // servicio es la autoridad que crea los roles plantilla de un tenant.
            'app/Modules/Identity/Application/ProvisionTenantRoles.php',

            // Y aquí el tenant combina permisos en sus propios roles, que es exactamente lo
            // que D10 le concede. La validación de que sólo use permisos del catálogo cerrado
            // y de módulos contratados está en los Form Requests de rol.
            'app/Modules/Identity/Http/Controllers/RoleController.php',
        ],
    );

    // Nota: este candado es textual y no puede distinguir `$rol->givePermissionTo()` de
    // `$usuario->givePermissionTo()`. El complemento es la aserción de base de datos del
    // test de aislamiento, que verifica que la tabla esté vacía en tiempo de ejecución.
    expect($infracciones)->toBe([], sprintf(
        "Asignación de permisos fuera de los seeders de roles (D10):\n  - %s",
        implode("\n  - ", $infracciones),
    ));
});

it('el servicio de autorización es la única puerta y está registrado', function () {
    expect(app(Authorize::class))->toBeInstanceOf(Authorize::class);

    // Singleton: dos instancias con caches distintas darían respuestas distintas a la
    // misma pregunta dentro del mismo request.
    expect(app(Authorize::class))->toBe(app(Authorize::class));
});

it('el escáner de código fuente encuentra algo, para no pasar por estar ciego', function () {
    // Autoverificación, igual que en el test de scopes: si el escáner dejara de leer
    // archivos, todos los candados de arriba pasarían por vacíos.
    $archivos = iterator_to_array(
        Finder::create()->files()->in(base_path('app'))->name('*.php'),
        false,
    );

    expect(count($archivos))->toBeGreaterThan(40);

    // Y encuentra un patrón que sabemos que existe.
    expect(SourceScanner::findUsages(['final class Authorize'], []))
        ->toContain('app/Modules/Shared/Application/Authorization/Authorize.php');
});
