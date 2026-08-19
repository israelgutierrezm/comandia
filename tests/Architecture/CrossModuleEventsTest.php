<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Events\CrossModuleEvent;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Finder\Finder;

/**
 * CANDADO: un evento que cruza módulos vive en el kernel y no lleva modelos (D231)
 *
 * ## El defecto que cierra
 *
 * Cuando un evento vive en el módulo que lo emite, **quien lo escucha declara depender de ese módulo**. En la
 * Iteración 3 pasó con `PurchaseReceiptConfirmed`: `Inventory` y `Costing` tuvieron que declarar `depends_on:
 * ['Purchasing']`, y de ahí salió un ciclo que hubo que romper a mano quitando relaciones de Eloquent (D209).
 *
 * La Iteración 4 lo multiplica: el POS emite hacia inventarios, finanzas, impresión y salón a la vez. Sin candado, el
 * grafo de módulos se llena de flechas hacia un módulo operativo —contra la regla 2 de §2— y nadie lo nota hasta que
 * hay que extraer algo.
 *
 * ## Las dos cosas que verifica
 *
 * 1. Todo evento con oyentes en **otro** módulo vive en `Shared\Domain\Events` e implementa `CrossModuleEvent`.
 * 2. Ningún evento de ese espacio referencia un **modelo Eloquent**: se serializan a la cola, y pasar un modelo para
 *    recargarlo al otro lado hace que el oyente actúe sobre un estado que ya no es el que disparó el evento.
 *
 * ## Se leen los oyentes REGISTRADOS, no los archivos
 *
 * El mapa sale del despachador de Laravel, ya arrancado con todos los proveedores. Leer los `Event::listen` con una
 * expresión regular —como hace el candado de oyentes registrados, donde es lo correcto porque busca ausencias— aquí
 * daría falsos negativos: un registro condicional o dentro de un bucle no se ve en el texto.
 */

/**
 * El módulo al que pertenece una clase, por su espacio de nombres.
 */
function moduloDe(string $clase): ?string
{
    if (preg_match('/^App\\\\Modules\\\\([A-Za-z]+)\\\\/', $clase, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * ¿El módulo es del shared kernel?
 *
 * Se lee del registro declarativo y no de una lista escrita aquí: el kernel son SIETE módulos —Shared, Tenancy,
 * Identity, Organization, Configuration, Audit y Notifications— y copiarlos haría que este candado se desincronizara
 * del registro en la primera iteración que agregue uno.
 *
 * Un evento emitido por el kernel puede vivir en su módulo sin problema: la regla 1 de §2 permite que cualquier módulo
 * dependa del kernel. Es el caso de `TenantProvisioned`, que `Catalog` escucha para sembrar las unidades del sistema.
 */
function esDelKernel(?string $modulo): bool
{
    if ($modulo === null) {
        return false;
    }

    return (config('comandia.modules')[$modulo]['layer'] ?? null) === 'kernel';
}

/**
 * Excepciones declaradas, con su razón escrita y su plan.
 *
 * Es el patrón que el proyecto ya usa para `withoutGlobalScopes`: una excepción no se comenta en el código y se olvida,
 * se **declara aquí** con el motivo. Un candado sin salida declarada se acaba apagando, y cuando alguien lo apaga se
 * lleva por delante lo que sí protegía.
 *
 * @var array<class-string, string>
 */
const EXCEPCIONES_DECLARADAS = [
    // `PurchaseReceiptConfirmed` sigue en `Purchasing` y lleva el modelo de la recepción.
    //
    // La razón es concreta, no inercia: su oyente de `Inventory` escribe el **enlace de vuelta** —el `movement_id` y el
    // `lot_id` de cada línea del documento— DENTRO de la misma transacción que crea el movimiento del kardex. Ese
    // enlace es lo que hace DETECTABLE una confirmación a medias (`was_applied` por línea), que fue la respuesta a uno
    // de los cinco defectos de D220: un fallo de oyente que hacía mentir a la confirmación.
    //
    // Invertirlo para que `Purchasing` escribiera su propia tabla exigiría que escuchara a `StockMovementRecorded`, y
    // ese evento se emite **fuera** de la transacción a propósito y con su razón escrita: quien escuche no debe poder
    // abortar la escritura del kardex. O sea que la inversión cambiaría un enlace atómico por uno reparable, y haría
    // falta una herramienta de reparación para un problema que hoy no existe.
    //
    // PLAN: migra cuando el enlace se **derive** del kardex en lugar de guardarse — `stock_movements` ya apunta al
    // documento origen por `source_type`/`source_id`, y lo único que falta para no necesitar la columna es saber la
    // línea. Ese cambio toca una tabla inmutable, así que se hace con su propio diseño y no de pasada.
    \App\Modules\Purchasing\Events\PurchaseReceiptConfirmed::class => 'Enlace de vuelta atómico; ver D236',
];

it('todo evento con oyentes en otro módulo vive en el kernel', function () {
    $fuera = [];

    foreach (Event::getRawListeners() as $evento => $oyentes) {
        if (! is_string($evento) || ! class_exists($evento)) {
            continue;
        }

        $moduloDelEvento = moduloDe($evento);

        // Los eventos del kernel ya están donde deben: depender del kernel está permitido por la regla 1.
        if ($moduloDelEvento === null || esDelKernel($moduloDelEvento)) {
            continue;
        }

        foreach ($oyentes as $oyente) {
            $nombre = is_string($oyente) ? $oyente : null;

            if ($nombre === null || ! class_exists($nombre)) {
                continue;
            }

            $moduloDelOyente = moduloDe($nombre);

            if ($moduloDelOyente === null || $moduloDelOyente === $moduloDelEvento) {
                continue;
            }

            if (array_key_exists($evento, EXCEPCIONES_DECLARADAS)) {
                continue;
            }

            $fuera[] = sprintf('%s (lo escucha %s, de %s)', $evento, $nombre, $moduloDelOyente);
        }
    }

    expect(array_values(array_unique($fuera)))->toBe([], sprintf(
        "Estos eventos los escucha OTRO módulo y viven en el módulo que los emite:\n  - %s\n\n".
        "Cuando el evento vive en el emisor, quien lo escucha acaba declarando `depends_on` de él, y el grafo de\n".
        "módulos se llena de flechas hacia arriba (regla 2 de §2). Pasa el evento a `App\\Modules\\Shared\\Domain\\Events`,\n".
        "hazlo implementar `CrossModuleEvent` y déjalo AUTOCONTENIDO con datos primitivos.\n\n".
        'Si hay una razón para no hacerlo, declárala en `EXCEPCIONES_DECLARADAS` con su motivo y su plan.',
        implode("\n  - ", array_unique($fuera)),
    ));
});

it('ningún evento del kernel referencia un modelo Eloquent', function () {
    $ruta = app_path('Modules/Shared/Domain/Events');

    expect(is_dir($ruta))->toBeTrue('No existe el espacio de eventos del kernel: el candado no está mirando nada.');

    $conModelos = [];

    foreach (Finder::create()->files()->in($ruta)->name('*.php') as $file) {
        $contenido = (string) $file->getContents();

        // Se busca el espacio de nombres de los modelos, que es el mismo en todos los módulos por convención. Buscar
        // `extends Model` no serviría: aquí no se declaran modelos, se importan.
        if (preg_match('/Infrastructure\\\\Models\\\\/', $contenido) === 1) {
            $conModelos[] = $file->getFilename();
        }
    }

    expect($conModelos)->toBe([], sprintf(
        "Estos eventos del kernel importan un modelo Eloquent:\n  - %s\n\n".
        "Los eventos que cruzan módulos se serializan a la cola. Un modelo se recarga al otro lado, y el oyente acaba\n".
        "actuando sobre un estado que ya no es el que disparó el evento — o sobre una fila que ya no existe.\n\n".
        'Manda ULIDs y montos como cadena, en DTOs inmutables.',
        implode("\n  - ", $conModelos),
    ));
});

it('todo evento del kernel implementa el contrato', function () {
    $ruta = app_path('Modules/Shared/Domain/Events');

    $sinContrato = [];

    foreach (Finder::create()->files()->in($ruta)->name('*.php') as $file) {
        $clase = 'App\\Modules\\Shared\\Domain\\Events\\'.$file->getFilenameWithoutExtension();

        if (! class_exists($clase)) {
            // Interfaces y DTOs viven aquí también; sólo se exige el contrato a las clases instanciables.
            continue;
        }

        if (! is_subclass_of($clase, CrossModuleEvent::class)) {
            $sinContrato[] = $file->getFilename();
        }
    }

    expect($sinContrato)->toBe([], sprintf(
        "Estas clases están en el espacio de eventos del kernel y no implementan `CrossModuleEvent`:\n  - %s\n\n".
        "El contrato no es decorativo: obliga a llevar el `tenantId`, que es lo que permite a un oyente abrir el\n".
        'contexto de negocio cuando corre en una cola, sin sesión ni petición.',
        implode("\n  - ", $sinContrato),
    ));
});
