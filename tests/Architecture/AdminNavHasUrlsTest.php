<?php

declare(strict_types=1);

/**
 * CANDADO: toda ruta del menú del administrador tiene URL en la tabla.
 *
 * El `AdminLayout` resuelve las URLs desde una tabla escrita a mano (`const urls`) en vez de Ziggy (una tabla es más
 * honesta que volcar el mapa de rutas al cliente, ADR de la barra). El precio es que un ítem nuevo del menú con un
 * `route:` que nadie agregó a `urls` cae, en silencio, a `/admin`: `routeUrl()` devuelve `/admin` por omisión.
 *
 * Eso rompe DOS cosas a la vez y ninguna lanza error: el enlace de la barra lleva al inicio en vez de a su pantalla, y
 * la **migaja de pan** —que se deriva del mismo árbol— apunta al inicio o no encuentra su sección. Como el defecto es
 * un enlace mal dirigido, no una excepción, no se ve leyendo el código ni tumba ninguna prueba de las demás.
 *
 * Compara los `route:` del árbol de navegación contra las claves de `urls`, ambos leídos del propio `AdminLayout.vue`.
 */
function adminLayoutFuente(): string
{
    return (string) file_get_contents(base_path('resources/js/layouts/AdminLayout.vue'));
}

/**
 * Los `route:` declarados en los ítems del menú.
 *
 * @return list<string>
 */
function rutasDelMenu(): array
{
    preg_match_all("/route:\s*'([a-z0-9._-]+)'/i", adminLayoutFuente(), $m);

    return array_values(array_unique($m[1]));
}

/**
 * Las claves de la tabla `const urls = { ... }`.
 *
 * @return list<string>
 */
function clavesDeUrls(): array
{
    $fuente = adminLayoutFuente();

    // El bloque de la tabla, acotado para no barrer comillas de otras partes del componente.
    if (preg_match('/const urls\s*=\s*\{(.*?)\};/s', $fuente, $bloque) !== 1) {
        return [];
    }

    preg_match_all("/'([a-z0-9._-]+)'\s*:/i", $bloque[1], $m);

    return array_values(array_unique($m[1]));
}

it('cada ruta del menú del administrador tiene una URL en la tabla', function () {
    $urls = clavesDeUrls();

    $faltantes = array_values(array_diff(rutasDelMenu(), $urls));

    sort($faltantes);

    expect($faltantes)->toBe([], sprintf(
        "Estas rutas del menú NO están en `const urls` de AdminLayout.vue:\n  - %s\n\n".
        "`routeUrl()` las manda a `/admin` en silencio: el enlace de la barra y su migaja de pan apuntan al inicio en\n".
        'vez de a su pantalla. Agrega cada ruta a la tabla `urls` con su ruta pública.',
        implode("\n  - ", $faltantes),
    ));
});

it('cada URL de la tabla lleva a una pantalla que existe', function () {
    // La otra mitad del defecto: una URL escrita a mano con una errata (o una pantalla que se movió) deja el enlace de la
    // barra en un 404, igual de silencioso que la ruta que falta. Se compara contra las rutas GET reales de Laravel.
    $existentes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($ruta): bool => in_array('GET', $ruta->methods(), true))
        ->map(fn ($ruta): string => '/'.trim($ruta->uri(), '/'))
        ->all();

    preg_match('/const urls\s*=\s*\{(.*?)\};/s', adminLayoutFuente(), $bloque);
    preg_match_all("/'([a-z0-9._-]+)'\s*:\s*'([^']+)'/i", $bloque[1] ?? '', $pares, PREG_SET_ORDER);

    $rotas = [];

    foreach ($pares as [, $clave, $url]) {
        if (! in_array('/'.trim($url, '/'), $existentes, true)) {
            $rotas[] = "{$clave} → {$url}";
        }
    }

    expect(count($pares))->toBeGreaterThanOrEqual(15)
        ->and($rotas)->toBe([], "Estas URLs del menú no son ninguna ruta GET de Laravel:\n  - ".implode("\n  - ", $rotas));
});

it('el candado mira donde tiene que mirar', function () {
    // Si los patrones dejan de encontrar el árbol o la tabla, la prueba de arriba pasaría en verde sobre nada.
    expect(count(rutasDelMenu()))->toBeGreaterThanOrEqual(15);
    expect(count(clavesDeUrls()))->toBeGreaterThanOrEqual(15);
});
