<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CANDADO: si la sucursal llega del CLIENTE, hay que comprobar el alcance.
 *
 * ## De dónde salió
 *
 * De abrir el navegador en el paso 20, no de la suite. Con Roma Norte como sucursal activa, la pantalla de caja ofrecía
 * la terminal de Polanco —las dos se llamaban «Caja 1», porque el nombre es único por sucursal— y abrirla devolvió
 * **201**. El turno quedó en Polanco, y de un turno cuelgan los cobros, los retiros y el corte.
 *
 * Al tirar del hilo, el mismo hueco estaba en **nueve** controladores: caja, cuentas, gastos, depósitos, liquidación de
 * propinas, planos de piso, agentes de impresión, terminales, impresoras, áreas y almacenes.
 *
 * ## Por qué ninguna prueba lo veía
 *
 * Porque el aislamiento de **tenant** —la línea que más se vigila en este proyecto— no dice nada de esto. La sucursal
 * ajena es del **mismo negocio**: pasa el global scope, pasa la validación `exists` con su `tenant_id`, y llega al
 * controlador como un modelo perfectamente válido. Quien tenía que decir que no es `membership_branch_scopes`.
 *
 * Y las pruebas de cada módulo usan siempre la sucursal de quien opera, porque es el caso normal. El defecto vive
 * exactamente donde nadie escribe pruebas por costumbre.
 *
 * ## La regla
 *
 * Un controlador que resuelve una `Branch` a partir de la **entrada del cliente** tiene que comprobar el alcance. Las
 * dos formas válidas son `AssertsBranchScope` (el guardián del kernel) y `AssertsWarehouseScope` (el de inventarios,
 * que además corta el almacén de tránsito).
 *
 * Sólo cuenta la sucursal que llega del cuerpo o de la URL. La que sale del **contexto** ya pasó por
 * `ResolveTenantContext`, que valida `X-Branch` contra el alcance de la membresía; ésa no necesita nada.
 *
 * ## Por qué un candado y no «acordarse»
 *
 * `Authorize::authorize($permiso, ?int $branchId = null)` acepta la sucursal como segundo argumento **opcional**. Ese
 * `= null` es la forma que toma el olvido: una llamada que lo omite se salta la comprobación y **nada falla**. Dos
 * encabezados del proyecto ya advertían por escrito que duplicar esta comprobación acaba mal —«los fallos de seguridad
 * por duplicación no avisan»— y aun así había tres copias y nueve endpoints sin ninguna. Una advertencia escrita no
 * impide repetir el fallo; sólo lo impide una prueba que falla sola.
 */

/**
 * Los controladores que resuelven una sucursal —o algo que la lleva dentro— a partir de lo que manda el cliente, para
 * ESCRIBIR con ella.
 *
 * Dos precisiones, cada una de las cuales costó una vuelta:
 *
 *   1. **La terminal cuenta.** La primera versión buscaba sólo `Branch`, y con eso no veía el caso que originó todo:
 *      abrir caja resuelve una `Terminal` y la sucursal sale de ella. Un candado que no encuentra el defecto que lo
 *      motivó no sirve. Lo mismo con la mesa, de la que cuelga la cuenta.
 *
 *   2. **Los filtros de lista NO cuentan.** `$builder->where('branch_id', ...)` es una consulta, no una escritura, y
 *      meterlos aquí llenaría el candado de ruido hasta volverlo inservible. Si un filtro debe rechazar una sucursal
 *      fuera de alcance —o devolver vacío— es otra pregunta, y está anotada como pendiente.
 *
 * @return array<string, string> ruta relativa => contenido
 */
function controladoresQueResuelvenSucursal(): array
{
    $archivos = Finder::create()
        ->files()
        ->in(base_path('app/Modules'))
        ->path('Http/Controllers')
        ->name('*.php');

    $encontrados = [];

    foreach ($archivos as $archivo) {
        $contenido = $archivo->getContents();

        // Los filtros de lista se quitan ANTES de buscar: son la mitad de las coincidencias y ninguna escribe nada.
        $sinFiltros = preg_replace('/^.*\$builder->.*$/m', '', $contenido) ?? $contenido;

        $resuelve = preg_match(
            '/(Branch|Terminal|RestaurantTable)::(findByUlid|query\(\)->where\()\s*[^;]{0,140}'
            .'\$(request|validado|validated)\b/',
            $sinFiltros,
        ) === 1;

        if ($resuelve) {
            $encontrados[str_replace('\\', '/', $archivo->getRelativePathname())] = $contenido;
        }
    }

    return $encontrados;
}

it('un controlador que resuelve la sucursal del cliente comprueba el alcance', function () {
    /**
     * Excepciones, con su motivo. Cada una es una promesa de que la comprobación está en otro sitio.
     *
     * @var array<string, string>
     */
    $declarados = [
        // Tiene su propia comprobación estática desde la Iteración 2, y Costing la reutiliza. Es el tercer sitio donde
        // vivía la misma regla antes de que existiera el guardián del kernel; migrarlo es trabajo aparte y hacerlo
        // ahora, en el mismo paso que el hallazgo, mezclaría un arreglo de seguridad con una limpieza.
        'Catalog/Http/Controllers/ArticleBranchOverrideController.php' => 'comprobación propia: assertBranchInScope()',

        // Los dos resuelven la sucursal para FILTRAR una consulta, no para escribir. El corte de `$builder->` de arriba
        // no los alcanza porque la resuelven en una línea y filtran en la siguiente.
        //
        // Que un filtro deba rechazar una sucursal fuera de alcance —o devolver vacío, que informa menos— es una
        // decisión de producto que no está tomada, y tomarla aquí de contrabando sería peor que dejarla anotada. Queda
        // como pendiente en el registro. Lo que sí está cerrado es que ninguno de los dos ESCRIBE nada.
        'Catalog/Http/Controllers/ArticleController.php' => 'sólo filtra el listado por sucursal',
        'Finance/Http/Controllers/JournalController.php' => 'sólo filtra el diario por sucursal',
    ];

    $sinComprobar = [];

    foreach (controladoresQueResuelvenSucursal() as $ruta => $contenido) {
        if (isset($declarados[$ruta])) {
            continue;
        }

        $comprueba = str_contains($contenido, 'assertBranchInScope')
            || str_contains($contenido, 'assertWarehouseInScope');

        if (! $comprueba) {
            $sinComprobar[] = $ruta;
        }
    }

    sort($sinComprobar);

    expect($sinComprobar)->toBe([], sprintf(
        "Estos controladores resuelven una sucursal con lo que manda el cliente y NO comprueban el alcance:\n  - %s\n\n".
        "Usa el trait `AssertsBranchScope` del kernel y llama a `assertBranchInScope()` con el id de la sucursal en\n".
        "cuanto la tengas, antes de crear nada.\n\n".
        'El `tenant_id` no protege de esto: la sucursal ajena es del MISMO negocio y llega como un modelo válido.',
        implode("\n  - ", $sinComprobar),
    ));
});

it('el candado mira donde tiene que mirar', function () {
    // Si el patrón deja de encontrar controladores —porque alguien cambió la forma de resolver una sucursal, o porque
    // el Finder se quedó apuntando a una carpeta que ya no existe— la prueba de arriba pasaría en VERDE sobre una lista
    // vacía, que es la peor forma de fallar: silenciosa y tranquilizadora.
    $encontrados = controladoresQueResuelvenSucursal();

    expect(count($encontrados))->toBeGreaterThanOrEqual(8);

    expect(array_keys($encontrados))->toContain('Pos/Http/Controllers/CashSessionController.php');
});
