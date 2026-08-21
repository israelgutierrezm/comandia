<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route as RouteFacade;
use Symfony\Component\Finder\Finder;

/**
 * CANDADO: un filtro que el frontend inventa deja la pantalla EN BLANCO, y ya van tres.
 *
 * ## Por qué duele tanto un filtro mal escrito
 *
 * La lista blanca de `ListQuery` **rechaza** lo que no reconoce, con un 422 (D182). Es lo correcto: ignorarlo en
 * silencio serviría datos que nadie pidió. Pero del lado del cliente ese 422 llega dentro de un `Promise.all` que
 * tumba **toda** la carga de la pantalla — incluido lo que ya había traído bien.
 *
 * El resultado no es «este filtro no funciona»: es la pantalla de la cuenta **completamente en blanco**, o la de
 * comandas eternamente «Cargando…». Y como el nombre inventado suele ser plausible —`is_sellable` en vez de
 * `available_in_pos`, `branch` en un listado que no lo admitía— no se detecta leyendo el código.
 *
 * Tres veces en dos iteraciones: D294 (dos casos) y D308. La tercera es la que justifica el mecanismo.
 *
 * ## Cómo compara
 *
 * Del lado del servidor, la lista blanca **real**: las llaves de `filters`, los `handledByCaller`, los `_from`/`_to`
 * de cada `dateRanges` y los reservados (`page`, `per_page`, `sort`, `search`, `cursor`) — exactamente lo que
 * `ListQuery::rejectUnknownFilters()` acepta.
 *
 * Del lado del cliente, cada `api.get('/ruta', { ... })` de los archivos `.vue` y `.js`.
 *
 * La ruta se resuelve contra el **router de verdad**, no contra una tabla escrita a mano: así el candado sigue al
 * proyecto cuando una ruta cambia de controlador.
 *
 * ## Lo que NO comprueba
 *
 * Llamadas con la ruta o los parámetros construidos dinámicamente —`api.get(url)` con la url en una variable—. Se
 * omiten en lugar de adivinarlas: un candado que inventa lo que no puede leer produce fallos falsos, y un candado con
 * fallos falsos se acaba desactivando. Lo que sí cubre es la forma en que están escritas casi todas las llamadas.
 */

/**
 * Las llaves de consulta que un endpoint acepta, leídas de su `ListQuery`.
 *
 * @return array<string, list<string>> ruta (`pos-tickets`) => llaves permitidas
 */
function listasBlancasDeLaApi(): array
{
    $reservados = ['page', 'per_page', 'sort', 'search', 'cursor'];
    $porControlador = [];

    $archivos = Finder::create()->files()->in(base_path('app/Modules'))->path('Http/Controllers')->name('*.php');

    foreach ($archivos as $archivo) {
        $contenido = $archivo->getContents();

        if (! str_contains($contenido, 'new ListQuery(')) {
            continue;
        }

        // El bloque del constructor, que es donde están las tres listas.
        $inicio = strpos($contenido, 'new ListQuery(');
        $bloque = substr($contenido, $inicio, 1200);

        $llaves = $reservados;

        // `filters: ['status' => 'status', ...]` — interesan las CLAVES, que son lo que el cliente manda.
        if (preg_match('/filters:\s*\[(.*?)\]/s', $bloque, $m) === 1) {
            preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $f);
            $llaves = [...$llaves, ...$f[1]];
        }

        if (preg_match('/handledByCaller:\s*\[(.*?)\]/s', $bloque, $m) === 1) {
            preg_match_all("/'([a-z_]+)'/", $m[1], $h);
            $llaves = [...$llaves, ...$h[1]];
        }

        if (preg_match('/dateRanges:\s*\[(.*?)\]/s', $bloque, $m) === 1) {
            preg_match_all("/'([a-z_]+)'\s*=>/", $m[1], $d);

            foreach ($d[1] as $prefijo) {
                $llaves[] = "{$prefijo}_from";
                $llaves[] = "{$prefijo}_to";
            }
        }

        $clase = 'App\\Modules'.str_replace(['/', '.php'], ['\\', ''], '\\'.str_replace('\\', '/', $archivo->getRelativePathname()));
        $porControlador[str_replace('/', '\\', $clase)] = array_values(array_unique($llaves));
    }

    // Del controlador a la RUTA, usando el router real.
    $porRuta = [];

    foreach (RouteFacade::getRoutes() as $ruta) {
        if (! in_array('GET', $ruta->methods(), strict: true) || ! str_starts_with($ruta->uri(), 'api/v1/')) {
            continue;
        }

        $accion = $ruta->getActionName();

        if (! str_contains($accion, '@index')) {
            continue;
        }

        $controlador = strtok($accion, '@');

        if (isset($porControlador[$controlador])) {
            $porRuta['/'.substr($ruta->uri(), strlen('api/v1/'))] = $porControlador[$controlador];
        }
    }

    return $porRuta;
}

/**
 * Cada `api.get('/ruta', { llaves })` escrito literalmente en el frontend.
 *
 * @return list<array{archivo: string, ruta: string, llaves: list<string>}>
 */
function llamadasDelFrontend(): array
{
    $llamadas = [];

    $archivos = Finder::create()->files()->in(base_path('resources/js'))->name('*.vue')->name('*.js');

    foreach ($archivos as $archivo) {
        $contenido = $archivo->getContents();

        // `api.get('/algo', { ... })` con la ruta literal. El objeto se lee hasta la primera llave de cierre, que
        // basta para estos objetos planos de parámetros.
        preg_match_all("/api\.get\(\s*'(\/[a-z0-9\-\/]+)'\s*,\s*\{([^}]*)\}/i", $contenido, $m, PREG_SET_ORDER);

        foreach ($m as $coincidencia) {
            // `(?:^|[,{])\s*` y no `(?:^|[,{]\s*)`: el cuerpo capturado empieza con el espacio que sigue a la llave,
            // así que con el espacio DENTRO del grupo alternativo el `^` no casaba y **la primera llave del objeto se
            // perdía**. Justo la primera es donde suele ir el filtro que importa: la primera versión de este candado
            // pasó en verde sobre el defecto que existe para atrapar, y lo descubrí rompiéndolo a propósito.
            preg_match_all('/(?:^|[,{])\s*([a-z_][a-z0-9_]*)\s*:/i', $coincidencia[2], $k);

            $llamadas[] = [
                'archivo' => str_replace('\\', '/', $archivo->getRelativePathname()),
                'ruta' => $coincidencia[1],
                'llaves' => $k[1],
            ];
        }
    }

    return $llamadas;
}

it('el frontend no inventa filtros que la API rechaza', function () {
    $blancas = listasBlancasDeLaApi();
    $problemas = [];

    foreach (llamadasDelFrontend() as $llamada) {
        // Rutas con parámetro —`/pos-accounts/{ulid}`— o que no son listados: no tienen lista blanca que comparar.
        if (! isset($blancas[$llamada['ruta']])) {
            continue;
        }

        $invalidas = array_values(array_diff($llamada['llaves'], $blancas[$llamada['ruta']]));

        if ($invalidas !== []) {
            $problemas[] = sprintf(
                '%s pide %s en %s. Permitidos: %s',
                $llamada['archivo'],
                implode(', ', $invalidas),
                $llamada['ruta'],
                implode(', ', $blancas[$llamada['ruta']]),
            );
        }
    }

    sort($problemas);

    expect($problemas)->toBe([], sprintf(
        "El frontend pide filtros que la API NO acepta:\n  - %s\n\n".
        "La lista blanca responde 422, y ese 422 tumba el `Promise.all` de la pantalla entera: no se ve «este filtro\n".
        "no funciona», se ve la pantalla en blanco o cargando para siempre.\n\n".
        'O el nombre está mal, o el filtro hay que agregarlo de verdad al `ListQuery` del controlador.',
        implode("\n  - ", $problemas),
    ));
});

it('el candado mira donde tiene que mirar', function () {
    // Si los patrones dejan de encontrar llamadas o listas blancas, la prueba de arriba pasaría en verde sobre nada —
    // la peor forma de fallar, silenciosa y tranquilizadora.
    expect(count(listasBlancasDeLaApi()))->toBeGreaterThanOrEqual(15);
    expect(count(llamadasDelFrontend()))->toBeGreaterThanOrEqual(20);
});
