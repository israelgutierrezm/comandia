<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CANDADO: un servicio no devuelve el resultado de `create()` sin releerlo.
 *
 * ## El defecto, que ya apareció CUATRO veces
 *
 * `Modelo::create(['quantity' => '1000'])` devuelve un modelo cuyo `quantity` es la cadena `'1000'` — no
 * `'1000.0000'`, que es lo que la base guardó en su `DECIMAL(12,4)`. Eloquent devuelve el atributo **asignado**, no el
 * almacenado, y el cast no rellena decimales que no se escribieron.
 *
 * Así que el `Resource` publica `'1000'` al crear y `'1000.0000'` al leer, y el cliente que formatea uno de los dos se
 * equivoca. Las cuatro apariciones:
 *
 *   - **D134** (Iteración 2): dinero con ocho decimales en la UI.
 *   - **D149** (cierre de la Iteración 2): `POST /units` y el alta de modificadores devolvían decimales sin escalar.
 *   - **Paso 4** de esta iteración: la merma devolvía `'1000'` en lugar de `'1000.0000'`.
 *   - **Paso 8**: el precio de proveedor devolvía `'480'` en lugar de `'480.0000'`.
 *
 * Cuatro veces es cuando deja de ser mala suerte. D205 lo dejó anotado como candidato a candado; éste es.
 *
 * ## La regla es ABSOLUTA, y la razón es más fuerte que los decimales
 *
 * Al escribir este candado esperaba una lista corta y encontré **diez** servicios. La primera reacción fue pensar en
 * excepciones —«en éste el controlador ya relee, en aquél no hay decimales»— y sería el error: una lista de excepciones
 * larga es una lista que nadie lee.
 *
 * Mirándolo otra vez, la razón para arreglar los diez es más fuerte que el problema de los decimales: **este proyecto usa
 * columnas generadas por todas partes** —`variance` en el conteo, `transit_difference` en la transferencia, `lot_key`,
 * `balance_after`— y una columna generada **nunca** está presente en un modelo recién creado. Ni con el valor viejo:
 * simplemente no existe como atributo.
 *
 * Así que la regla no es «releer cuando haya decimales», es **un servicio devuelve lo que la base tiene**. Sin
 * condiciones, sin excepciones que recordar, y con un costo de un `SELECT` por escritura que a esta escala no se nota.
 *
 * ## Qué comprueba
 *
 * Un `return Modelo::create(...)` directo, o un `$x = Modelo::create(...)` seguido de `return $x;` sin `refresh()` en
 * medio. Es análisis de texto: reconoce las dos formas que producen el defecto, no el flujo de datos.
 *
 * ## Por qué en los servicios y no en los controladores
 *
 * Porque es donde vive la escritura. Un controlador que llame a un servicio ya recibe el modelo releído, y varios
 * controladores hacen `->refresh()` por su cuenta —cinturón y tirantes— que no estorba pero tampoco es el sitio donde la
 * regla pertenece.
 */
it('ningún servicio devuelve el resultado de create() sin releerlo', function () {
    $sospechosos = [];

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Application')->name('*.php') as $file) {
        $contenido = $file->getContents();
        $relativa = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        // Patrón 1: `return Modelo::create(` — el más directo.
        if (preg_match('/return\s+([A-Z][A-Za-z0-9_]*)::create\s*\(/', $contenido, $m) === 1) {
            $sospechosos[] = "{$relativa}: `return {$m[1]}::create(...)` sin releer";
        }

        // Patrón 2: `$x = Modelo::create(...)` y más abajo `return $x;` sin un `refresh()` de por medio.
        //
        // Se mira el archivo completo y no el método, que es una simplificación consciente: un `refresh()` en otro
        // método del mismo archivo silencia el aviso. Se acepta porque el falso NEGATIVO es barato —el defecto vuelve a
        // aparecer y se agrega el caso— y un falso positivo haría que alguien apague el candado.
        if (preg_match_all('/\$([a-zA-Z0-9_]+)\s*=\s*[A-Z][A-Za-z0-9_]*::create\s*\(/', $contenido, $ms) > 0) {
            foreach ($ms[1] as $variable) {
                $devuelveCrudo = preg_match('/return\s+\$'.preg_quote($variable, '/').'\s*;/', $contenido) === 1;
                $seRelee = str_contains($contenido, '$'.$variable.'->refresh()')
                    || str_contains($contenido, '$'.$variable.'->fresh()');

                if ($devuelveCrudo && ! $seRelee) {
                    $sospechosos[] = "{$relativa}: devuelve `\${$variable}` recién creado sin releerlo";
                }
            }
        }
    }

    expect(array_values(array_unique($sospechosos)))->toBe([], sprintf(
        "Estos servicios devuelven un modelo recién creado sin releerlo:\n  - %s\n\n".
        "Eloquent devuelve el atributo ASIGNADO y no el almacenado: un `DECIMAL(12,4)` al que se le escribió `'1000'`\n".
        "vuelve como `'1000'` y no como `'1000.0000'`, así que el `Resource` publica una cosa al crear y otra al leer.\n".
        "Ya pasó cuatro veces (D134, D149, y los pasos 4 y 8 de la Iteración 3).\n\n".
        'Añade `->refresh()` antes de devolver.',
        implode("\n  - ", array_unique($sospechosos)),
    ));
});

it('el candado se mira a sí mismo', function () {
    // Meta-verificación: si el recolector dejara de encontrar archivos de servicio, la prueba de arriba pasaría en verde
    // sin mirar nada. Y se comprueba además que el patrón RECONOCE un `create()` cuando lo hay — sin eso, una expresión
    // regular mal escrita daría el mismo verde silencioso.
    $servicios = 0;
    $conCreate = 0;

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Application')->name('*.php') as $file) {
        $servicios++;

        if (preg_match('/[A-Z][A-Za-z0-9_]*::create\s*\(/', $file->getContents()) === 1) {
            $conCreate++;
        }
    }

    expect($servicios)->toBeGreaterThan(
        15,
        'El candado no encontró apenas servicios: la búsqueda dejó de funcionar.',
    );

    expect($conCreate)->toBeGreaterThan(
        3,
        'El patrón no reconoció ningún `create()`: la expresión regular dejó de funcionar y arriba no mira nada.',
    );
});
