<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * TODA RUTA DE `/api/v1` APARECE EN AL MENOS UNA PRUEBA
 *
 * ## El defecto que este candado cierra
 *
 * No es una aserción débil: es **código que nadie ha ejecutado**. Al cerrar la Iteración 2 se auditó qué
 * endpoints no se habían llamado nunca y salieron **diecinueve de ciento uno**, entre ellos:
 *
 *   - `GET` y `PUT` del **perfil laboral**, que respondían **500 desde la Iteración 1** — había prueba del
 *     `DELETE`, que devuelve 204 y no pasa por el recurso.
 *   - `POST /authorizations`, la **autorización por PIN**: el único endpoint sin permiso y el único con
 *     límite de intentos, o sea la superficie que un atacante usaría para probar diez mil combinaciones.
 *   - Los CRUD completos de unidades, categorías, etiquetas y presentaciones de compra. Al llamarlos por
 *     primera vez aparecieron tres defectos más: la cantidad de una presentación se podía cambiar —es el
 *     divisor de los costos ya capturados—, la ruta anidada no verificaba que la presentación fuera del
 *     artículo de la URL, y dos endpoints devolvían decimales con distinta escala que sus lecturas.
 *
 * La cobertura por módulo no basta para detectar esto, porque un módulo con veinte pruebas y tres endpoints
 * sin llamar se ve igual de sano que uno completo.
 *
 * ## Qué NO comprueba
 *
 * Que la prueba sea buena. Una ruta mencionada en un `assertForbidden` cuenta como ejercitada, y eso es
 * deliberado: este candado mide la existencia de un llamador, no la calidad de la aserción. Es un piso, no
 * un techo — pero es un piso que estuvo por debajo de cero durante dos iteraciones.
 */
it('ninguna ruta de la API queda sin llamar en las pruebas', function () {
    $rutas = [];

    foreach (Route::getRoutes() as $ruta) {
        if (! str_starts_with($ruta->uri(), 'api/v1')) {
            continue;
        }

        foreach ($ruta->methods() as $metodo) {
            // `HEAD` y `OPTIONS` los agrega el router y nadie los llama a mano.
            if (in_array($metodo, ['HEAD', 'OPTIONS'], strict: true)) {
                continue;
            }

            $rutas[$metodo.' /'.$ruta->uri()] = $ruta->uri();
        }
    }

    expect($rutas)->not->toBeEmpty('No se encontró ninguna ruta de la API: el candado no está mirando nada.');

    $fuente = testSourceText();
    $sinLlamar = [];

    foreach ($rutas as $etiqueta => $uri) {
        if (! uriAppearsIn($uri, $fuente)) {
            $sinLlamar[] = $etiqueta;
        }
    }

    expect($sinLlamar)->toBe([], sprintf(
        "Estas rutas no aparecen en ninguna prueba, o sea que NADIE las ha ejecutado:\n  - %s\n\n".
        'Un endpoint sin llamar no tiene una aserción débil: tiene cero. Los dos 500 más antiguos del '.
        'proyecto vivían justo aquí.',
        implode("\n  - ", $sinLlamar),
    ));
});

it('el candado detecta de verdad una ruta sin llamar', function () {
    // META-VERIFICACIÓN. Si el emparejamiento se rompiera —un cambio en cómo se escriben las URL en las
    // pruebas, por ejemplo— el candado pasaría en verde sin comprobar nada.
    $fuente = testSourceText();

    // Una URI que ninguna prueba puede mencionar. Se arma por concatenación a propósito: escrita completa
    // aparecería en ESTE archivo, que también se escanea, y la meta-verificación se contradiría a sí misma.
    $imposible = 'api/v1/'.'ruta-'.'que-'.'no-'.'existe';

    expect(uriAppearsIn($imposible, $fuente))
        ->toBeFalse('El candado encuentra una ruta inexistente: el emparejamiento acepta cualquier cosa.');

    // Y una que seguro está, con parámetro incluido.
    expect(uriAppearsIn('api/v1/articles/{article}/recipe', $fuente))
        ->toBeTrue('El candado NO encuentra una ruta que sí se llama: dejó de emparejar parámetros.');
});

/** Todo el código de las pruebas, en una sola cadena. */
function testSourceText(): string
{
    static $fuente = null;

    if ($fuente !== null) {
        return $fuente;
    }

    $fuente = '';

    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterador as $archivo) {
        if ($archivo->isFile() && $archivo->getExtension() === 'php') {
            $fuente .= file_get_contents($archivo->getPathname());
        }
    }

    return $fuente;
}

/**
 * ¿Aparece esta URI en el texto de las pruebas?
 *
 * Cada `{parametro}` de la ruta puede ser cualquier cosa en la prueba: una interpolación `{$x->ulid}`, un
 * ULID literal o una variable. Se parte la URI por sus parámetros y se exige que los trozos aparezcan en
 * orden, separados por algo que no sea una diagonal ni un espacio — así `articles/{article}/recipe` empareja
 * con `articles/{$queso->ulid}/recipe` y no con `articles/x/y/recipe`.
 */
function uriAppearsIn(string $uri, string $fuente): bool
{
    $trozos = array_map(
        fn (string $trozo): string => preg_quote($trozo, '#'),
        preg_split('#\{[^}]+\}#', $uri) ?: []
    );

    return preg_match('#'.implode('[^/\s"\']+', $trozos).'#', $fuente) === 1;
}
