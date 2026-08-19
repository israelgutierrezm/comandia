<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CANDADO: todo oyente escrito tiene que estar registrado.
 *
 * ## Por qué hace falta
 *
 * Un oyente sin `Event::listen` **no falla**: simplemente no corre. El código existe, se ve bien, tiene pruebas
 * unitarias si alguien las escribió, y en producción no pasa nada — la mercancía no entra al kardex, el costo no se
 * captura, y nadie ve un error.
 *
 * En la Iteración 3 eso pasó a ser un riesgo real: confirmar una recepción tiene **tres** efectos y cada uno vive en un
 * oyente distinto, dos de ellos en otros módulos. Un olvido en el proveedor de servicios de cualquiera de los tres
 * dejaría el sistema silenciosamente incompleto, y las pruebas de recepción lo habrían atrapado sólo porque comprueban
 * los efectos — si alguna se hubiera escrito comprobando sólo la respuesta HTTP, el olvido habría pasado.
 *
 * ## Y también al revés
 *
 * Un evento que nadie despacha es código muerto que parece vivo: alguien escribirá un oyente para él y esperará que
 * corra. Se comprueba con una salvedad explícita — `StockMovementRecorded` se emite desde el primer día **sin
 * suscriptores a propósito** (está documentado en su módulo), así que la regla es «se despacha», no «se escucha».
 */
it('todo oyente está registrado con Event::listen', function () {
    $fuenteDeProveedores = '';

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Providers')->name('*.php') as $file) {
        $fuenteDeProveedores .= $file->getContents();
    }

    // También el proveedor global, por si algún día registra ahí.
    foreach (Finder::create()->files()->in(app_path('Providers'))->name('*.php') as $file) {
        $fuenteDeProveedores .= $file->getContents();
    }

    $sinRegistrar = [];

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Listeners')->name('*.php') as $file) {
        $nombre = $file->getFilenameWithoutExtension();

        // Se busca el nombre corto de la clase: es como se registra (`Event::listen(Evento::class, Oyente::class)`),
        // con el `use` arriba. Buscar el FQCN daría falsos positivos en cuanto alguien lo importe.
        if (! str_contains($fuenteDeProveedores, $nombre.'::class')) {
            $sinRegistrar[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($sinRegistrar)->toBe([], sprintf(
        "Estos oyentes existen y NADIE los registra:\n  - %s\n\n".
        "Un oyente sin `Event::listen` no falla: no corre. El efecto que debía producir simplemente no ocurre, y en\n".
        "producción no se ve ningún error.\n\n".
        'Regístralo en el `boot()` del proveedor de su módulo.',
        implode("\n  - ", $sinRegistrar),
    ));
});

it('todo evento se despacha desde algún sitio', function () {
    // Un evento que nadie emite es código muerto que parece vivo: alguien le escribirá un oyente y esperará que corra.
    $fuenteDelDominio = '';

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $ruta = $file->getPathname();

        // Se excluye la carpeta de eventos: la declaración de la clase no cuenta como despacho.
        if (str_contains($ruta, DIRECTORY_SEPARATOR.'Events'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $fuenteDelDominio .= $file->getContents();
    }

    $sinDespachar = [];

    foreach (Finder::create()->files()->in(app_path('Modules'))->path('Events')->name('*.php') as $file) {
        $nombre = $file->getFilenameWithoutExtension();

        // `Evento::dispatch(` o `new Evento(` — las dos formas de emitirlo.
        $seDespacha = str_contains($fuenteDelDominio, $nombre.'::dispatch')
            || str_contains($fuenteDelDominio, 'new '.$nombre.'(');

        if (! $seDespacha) {
            $sinDespachar[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    expect($sinDespachar)->toBe([], sprintf(
        "Estos eventos están declarados y NADIE los emite:\n  - %s\n\n".
        'Un evento que nadie despacha es código muerto que parece vivo.',
        implode("\n  - ", $sinDespachar),
    ));
});

it('el candado se mira a sí mismo', function () {
    // Meta-verificación. Si el recolector dejara de encontrar oyentes —una carpeta movida, un patrón que cambia— las
    // dos pruebas de arriba pasarían en verde sin mirar nada, que es el peor resultado posible para un candado. Es la
    // lección que ya costó dos pruebas de concurrencia falsas en esta iteración (D155, D167).
    $oyentes = iterator_count(
        Finder::create()->files()->in(app_path('Modules'))->path('Listeners')->name('*.php')->getIterator()
    );

    $eventos = iterator_count(
        Finder::create()->files()->in(app_path('Modules'))->path('Events')->name('*.php')->getIterator()
    );

    expect($oyentes)->toBeGreaterThanOrEqual(
        5,
        'El candado no encontró apenas oyentes: la búsqueda dejó de funcionar y arriba no está mirando nada.',
    );

    expect($eventos)->toBeGreaterThanOrEqual(
        4,
        'El candado no encontró apenas eventos: la búsqueda dejó de funcionar.',
    );
});
