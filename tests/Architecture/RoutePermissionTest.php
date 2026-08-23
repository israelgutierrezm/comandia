<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\PermissionCatalog;
use Illuminate\Support\Facades\Route;

/**
 * TODA RUTA DE `/api/v1` EXIGE UN PERMISO, Y EL PERMISO EXISTE
 *
 * Dos candados sobre el mismo hecho, y los dos cierran fallos que no se ven:
 *
 * 1. **Una ruta sin permiso es un endpoint abierto.** Basta olvidar el middleware al agregar una ruta para que
 *    cualquier usuario autenticado —un mesero— pueda tocarla. No falla nada, no hay error en ningún log: la
 *    ruta simplemente funciona para todos. Con más de setenta rutas y nueve iteraciones por delante, revisarlo
 *    a mano no es una estrategia.
 *
 * 2. **Un permiso mal escrito es un 403 permanente.** `can:catalog.artcles.view` no falla al arrancar: falla al
 *    usarse, con un "no tienes permiso" que parece un problema de configuración de roles. El tenant marcaría y
 *    desmarcaría casillas sin que nada cambie, porque el permiso que la ruta pide no existe en el catálogo
 *    cerrado de D10.
 */

/**
 * Las rutas de `/api/v1` que NO exigen permiso, con su justificación escrita.
 *
 * Tres, y cada una tiene una razón que no admite un permiso:
 *
 * - `api/v1` — el descubrimiento de la API. No devuelve dato alguno del negocio, sólo su versión y su nombre.
 * - `api/v1/context` — "¿quién soy y qué puedo hacer aquí?". Exigirle un permiso sería circular: es el endpoint
 *   del que el cliente saca la lista de permisos.
 * - `api/v1/authorizations` — la autorización por PIN (ADR-008). Tiene su propio mecanismo, deliberadamente
 *   distinto del rol activo, y por eso no puede depender de un permiso del rol activo.
 *
 * Y las del AGENTE DE IMPRESIÓN, que son una cuarta razón y no una excepción de conveniencia:
 *
 * - `api/v1/print-agent/*` — el contrato del agente (§9.3, §9.4). Un agente **no es un usuario**: no tiene membresía, no
 *   tiene rol activo y por tanto no puede tener un permiso del rol activo, que es lo único que este candado sabe
 *   comprobar. Su identidad es un token propio y su alcance es una sola sucursal, y eso lo impone
 *   `AuthenticatePrintAgent`, que resuelve el tenant del agente y nunca de la petición.
 *
 *   Darle una membresía habría hecho que pasara este candado sin decir palabra, y habría sido el error: le abriría la
 *   API entera a un proceso que corre sin vigilancia en una computadora de cocina. Aparecer aquí, con su justificación,
 *   es preferible a pasar en silencio.
 *
 * Y la QUINTA razón, de la Iteración 5:
 *
 * - `api/v1/broadcasting/auth` — la autorización de canales privados. No puede declarar un permiso en la ruta porque
 *   **el permiso depende del canal que se pida**: el del piso exige `floor.layouts.view` y el de un área exige
 *   `printing.jobs.view`. La comprobación vive en `routes/channels.php` y en `ChannelAccess`, que además del permiso
 *   verifica el tenant del canal y el alcance de sucursal — tres cosas, no una (D301, D302).
 *
 *   Ponerle aquí un permiso fijo sería peor que no ponerlo: haría creer que está resuelto, y el canal que no
 *   correspondiera a ese permiso quedaría autorizado por la ruta.
 *
 * Agregar una sexta es una decisión de arquitectura, no un detalle de implementación.
 *
 * @var list<string>
 */
$sinPermiso = [
    'api/v1',
    'api/v1/context',
    'api/v1/authorizations',
    'api/v1/broadcasting/auth',
    'api/v1/print-agent/jobs/next',
    'api/v1/print-agent/jobs/{printJob}/printed',
    'api/v1/print-agent/jobs/{printJob}/failed',

    // La SEXTA razón, de la Iteración 7: el MOTOR DE REPORTES (ADR-006/ADR-007).
    //
    // - `api/v1/reports` — el catálogo. Sólo lista los reportes que el rol activo puede ver; no devuelve dato alguno del
    //   negocio, igual que `context` lista los permisos sin exigir uno.
    // - `api/v1/reports/{report}` y `.../definition` — no pueden declarar un permiso FIJO en la ruta porque **el permiso
    //   depende del reporte pedido**: cada definición declara el suyo (ADR-006 regla 3) y el motor lo verifica en código.
    //   Es exactamente el caso de `broadcasting/auth`, cuyo permiso depende del canal. Un `can:` fijo aquí sería peor que
    //   no ponerlo: haría creer que está resuelto, y un reporte con otro permiso quedaría autorizado por la ruta.
    'api/v1/reports',
    'api/v1/reports/{report}',
    'api/v1/reports/{report}/definition',

    // Exportación (Tanda B): crear un export verifica en código el permiso del reporte pedido (como el motor); listar,
    // consultar y descargar están acotados al AUTOR del export (su propia membresía), no a un permiso fijo — como «mis
    // notificaciones». Un permiso en la ruta no expresaría ni «depende del reporte» ni «sólo el autor».
    'api/v1/reports/{report}/exports',
    'api/v1/report-exports',
    'api/v1/report-exports/{reportExport}',
    'api/v1/report-exports/{reportExport}/download',

    // El semáforo (Tanda C) corre el reporte para leer el valor real, así que su permiso es el del reporte (in-code),
    // como el resto del motor.
    'api/v1/reports/{report}/goal-status',

    // Notificaciones (Tanda D2): avisos PERSONALES, dirigidos a la membresía o al rol activo de quien mira. No hay
    // permiso fijo —como «mi contexto»—; el controlador los acota al destinatario.
    'api/v1/notifications',
    'api/v1/notifications/read-all',
    'api/v1/notifications/{notification}/read',
];

it('toda ruta de la API exige un permiso, salvo las declaradas', function () use ($sinPermiso) {
    $abiertas = [];

    foreach (Route::getRoutes() as $ruta) {
        if (! str_starts_with($ruta->uri(), 'api/v1')) {
            continue;
        }

        if (in_array($ruta->uri(), $sinPermiso, strict: true)) {
            continue;
        }

        if (permissionOf($ruta) === null) {
            $abiertas[] = sprintf('%s %s', implode('|', $ruta->methods()), $ruta->uri());
        }
    }

    expect($abiertas)->toBe([], sprintf(
        "ENDPOINTS SIN PERMISO. Cualquier usuario autenticado puede usarlos, y nada falla al hacerlo:\n  - %s\n\n".
        'Agrega `can:` o `can.write:` a la ruta, o declara la excepción con su justificación en este archivo.',
        implode("\n  - ", $abiertas),
    ));
});

it('todo permiso exigido por una ruta existe en el catálogo cerrado', function () {
    // Un typo aquí no falla al arrancar: produce un 403 permanente que parece un problema de configuración de
    // roles, y el tenant marcaría casillas sin que nada cambie.
    $conocidos = PermissionCatalog::names();
    $desconocidos = [];

    foreach (Route::getRoutes() as $ruta) {
        $permiso = permissionOf($ruta);

        if ($permiso === null) {
            continue;
        }

        if (! in_array($permiso, $conocidos, strict: true)) {
            $desconocidos[] = sprintf('%s → %s', $ruta->uri(), $permiso);
        }
    }

    expect($desconocidos)->toBe([], sprintf(
        "Estas rutas exigen un permiso que NO está en el catálogo (D10). Serán un 403 permanente:\n  - %s",
        implode("\n  - ", $desconocidos),
    ));
});

it('los candados NO están mirando al vacío', function () use ($sinPermiso) {
    // Meta-verificación. Los dos recorren las rutas buscando middleware; si el recorrido se rompiera —un cambio
    // en cómo Laravel expone el middleware, por ejemplo— pasarían en verde sin comprobar nada, que es el modo de
    // fallo más peligroso de un candado estructural.
    $conPermiso = 0;

    foreach (Route::getRoutes() as $ruta) {
        if (permissionOf($ruta) !== null) {
            $conPermiso++;
        }
    }

    // Al cerrar la Iteración 2 hay más de setenta rutas con permiso. El umbral es deliberadamente bajo: lo que
    // verifica es que el detector VE permisos, no cuántos hay.
    expect($conPermiso)->toBeGreaterThan(50, 'El detector no encontró rutas con permiso: no está leyendo el middleware.');

    // Y que la lista de excepciones siga correspondiendo a rutas reales: una excepción para una ruta que ya no
    // existe es una puerta abierta esperando que alguien vuelva a usar esa URL.
    $uris = array_map(fn ($ruta): string => $ruta->uri(), iterator_to_array(Route::getRoutes()));

    foreach ($sinPermiso as $excepcion) {
        expect(in_array($excepcion, $uris, strict: true))
            ->toBeTrue("La excepción «{$excepcion}» ya no corresponde a ninguna ruta: quítala.");
    }
});

/**
 * El permiso que exige una ruta, o `null` si no exige ninguno.
 *
 * Mira el middleware ya reunido —`gatherMiddleware()`— y no sólo el declarado en la ruta, para que un permiso
 * puesto en el grupo cuente igual que uno puesto en la ruta.
 */
function permissionOf(Illuminate\Routing\Route $ruta): ?string
{
    foreach ($ruta->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        foreach (['can:', 'can.write:'] as $prefijo) {
            if (str_starts_with($middleware, $prefijo)) {
                return substr($middleware, strlen($prefijo));
            }
        }
    }

    return null;
}
