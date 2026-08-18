<?php

declare(strict_types=1);

/**
 * LOS REFS DE LOS COMPOSABLES SE LEEN CON `.value` EN LAS PLANTILLAS
 *
 * ## El defecto que este candado cierra
 *
 * `useApiForm` devuelve `{ processing, fieldErrors, generalError }`, y los tres son **refs**. Vue
 * desenvuelve refs automáticamente sólo cuando son bindings de primer nivel del `setup`; al llegar como
 * propiedad de un objeto —`save.generalError`— **no los desenvuelve**.
 *
 * Consecuencia exacta: `v-if="save.generalError"` es SIEMPRE verdadero, porque el objeto Ref existe
 * aunque su valor sea `null`. Y `{{ save.generalError }}` imprime vacío, porque la interpolación sí
 * desenvuelve. El resultado en pantalla es un **recuadro de error rojo, vacío, permanente**.
 *
 * Estaba en las nueve pantallas de la Iteración 1 y se repitió en las seis de la Iteración 2: treinta y
 * cinco veces. Ninguna prueba lo vio —no montan Vue— y a simple vista no llama la atención, porque un
 * error de verdad SÍ se muestra bien: lo único que sobra es una caja vacía cuando no hay error. Lo
 * encontró el navegador, al inspeccionar por qué había un `.alert` en una pantalla sin fallos.
 *
 * ## Por qué un candado y no sólo la corrección
 *
 * Porque la forma equivocada es la que se escribe sola. `save.generalError` se lee natural, funciona
 * «casi», y la próxima pantalla lo repetiría. El candado convierte un error silencioso en una prueba
 * roja.
 */

/**
 * Las propiedades vigiladas: las que fallan **en silencio** si se leen sin `.value`.
 *
 * Son las tres que sólo existen como refs de un composable en todo el proyecto, así que nombrarlas no
 * produce falsos positivos.
 *
 * Quedan fuera a propósito:
 *
 *   - `filters` es un objeto `reactive` y se usa sin `.value` correctamente.
 *   - `loading` y `error` son, además, refs de primer nivel de varios componentes, donde Vue sí los
 *     desenvuelve solo.
 *   - `items` y `meta` son nombres genéricos —`section.items` en la navegación, `.meta` como clase de
 *     CSS— y vigilarlos daba siete falsos positivos. Además fallan RUIDOSAMENTE: un `v-for` sobre un
 *     Ref no itera, y una tabla vacía se nota al primer vistazo. Este candado existe para lo que no se
 *     nota.
 *
 * @var list<string>
 */
$refProperties = ['generalError', 'fieldErrors', 'isEmpty'];

/**
 * @return list<string> rutas de todos los componentes Vue
 */
function vueFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'vue') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

it('ninguna plantilla lee un ref de composable sin .value', function () use ($refProperties) {
    $archivos = vueFiles();

    expect($archivos)->not->toBeEmpty('No se encontró ningún componente Vue: el candado no está mirando nada.');

    $patron = sprintf('/\.(%s)\b(?!\.value)/', implode('|', $refProperties));
    $fallas = [];

    foreach ($archivos as $ruta) {
        $contenido = (string) file_get_contents($ruta);
        $relativa = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $ruta));

        foreach (explode("\n", $contenido) as $numero => $linea) {
            if (preg_match($patron, $linea) === 1) {
                $fallas[] = sprintf('%s:%d → %s', $relativa, $numero + 1, trim($linea));
            }
        }
    }

    expect($fallas)->toBe([], sprintf(
        "Estas líneas leen un ref de composable sin `.value`:\n  - %s\n\n".
        'Un Ref es SIEMPRE verdadero como objeto, así que `v-if` se cumple aunque el valor sea `null`: '.
        'de ahí salen los recuadros de error vacíos y permanentes. Agrega `.value`.',
        implode("\n  - ", $fallas),
    ));
});

it('el candado detecta la forma equivocada', function () use ($refProperties) {
    // Meta-verificación. Sin esto, un cambio en la expresión regular podría dejar el candado en verde
    // sin comprobar nada — el modo de fallo más peligroso de una prueba estructural.
    $patron = sprintf('/\.(%s)\b(?!\.value)/', implode('|', $refProperties));

    expect(preg_match($patron, '<p v-if="save.generalError" class="alert">'))
        ->toBe(1, 'El candado NO detecta la forma equivocada: la expresión regular dejó de servir.');

    expect(preg_match($patron, '<p v-if="save.generalError.value" class="alert">'))
        ->toBe(0, 'El candado marca la forma CORRECTA como error.');

    // Y que no confunda `filters`, que se usa sin `.value` a propósito.
    expect(preg_match($patron, '<input v-model="list.filters.search" />'))
        ->toBe(0, 'El candado marca `filters`, que es un objeto reactive y no lleva `.value`.');
});
