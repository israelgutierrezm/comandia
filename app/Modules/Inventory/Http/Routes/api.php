<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\ArticleLotController;
use App\Modules\Inventory\Http\Controllers\KardexController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\WasteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Inventory — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así este archivo es la respuesta completa a «¿qué permiso
| hace falta para esto?», que es la pregunta que se hace al auditar. `can.write` en las escrituras deja visible
| qué endpoints escriben — un tenant en sólo lectura por impago los recibe con 403 y sigue pudiendo consultar.
|
| ## Tres endpoints de escritura, uno por permiso del catálogo
|
| `entries`, `exits` y `adjustments` son tres permisos distintos en el catálogo cerrado (D10), así que son tres
| rutas. Un endpoint único con un campo `kind` no podría declarar su permiso —`can:` recibe uno— y quedaría
| invisible para el candado de D129, que es el que garantiza que ningún endpoint quede abierto.
|
| Y un `kind` libre en el cuerpo permitiría registrar a mano un consumo por venta o una transferencia, que
| pertenecen a un documento: un movimiento sin su documento origen es un movimiento que nadie puede explicar.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Consulta de existencias ----
    //
    // Tres lecturas del mismo saldo porque son tres preguntas distintas, y cada una tiene su índice en
    // `article_stocks`: «¿qué tengo?», «¿dónde está mi queso?» y «¿qué hay en este almacén?».
    Route::get('stocks', [StockController::class, 'index'])
        ->middleware('can:inventory.stock.view')->name('stocks.index');

    Route::get('articles/{article}/stock', [StockController::class, 'forArticle'])
        ->middleware('can:inventory.stock.view')->name('articles.stock');

    Route::get('warehouses/{warehouse}/stocks', [StockController::class, 'forWarehouse'])
        ->middleware('can:inventory.stock.view')->name('warehouses.stocks');

    // ---- Kardex ----
    //
    // Permiso PROPIO, distinto de ver existencias: el saldo dice cuánto hay; el kardex dice **quién lo movió**
    // y cuándo, que es información de control. Un almacenista consulta saldos todo el día; auditar quién ajustó
    // qué es otra cosa.
    Route::get('articles/{article}/kardex', KardexController::class)
        ->middleware('can:inventory.kardex.view')->name('articles.kardex');

    // El catálogo de tipos de movimiento, para que el cliente arme su filtro sin escribir las etiquetas a mano
    // (la lección de D139). Con el permiso de ver el kardex, que es donde se usa.
    Route::get('stock-movement-kinds', [KardexController::class, 'kinds'])
        ->middleware('can:inventory.kardex.view')->name('stock-movement-kinds.index');

    // ---- Lotes y caducidades (D23) ----
    //
    // Estos endpoints existen aunque FEFO sea automático, y por dos razones. La primera: el permiso
    // `inventory.lots.manage` llevaba dos iteraciones en el catálogo cerrado sin ruta, que es exactamente el
    // defecto que la revisión de la Iteración 2 encontró con otro permiso (D140) — repetirlo a sabiendas sería
    // peor. La segunda: corregir una caducidad mal teclada y dar un lote por caducado son decisiones que sólo
    // una persona puede tomar.
    //
    // Se LEEN con `inventory.stock.view` y se administran con su permiso propio, por lo mismo que los datos de
    // referencia del catálogo (D99): cualquiera que consulte existencias necesita ver de qué lotes se componen.
    Route::get('articles/{article}/lots', [ArticleLotController::class, 'index'])
        ->middleware('can:inventory.stock.view')->name('articles.lots.index');

    Route::post('articles/{article}/lots', [ArticleLotController::class, 'store'])
        ->middleware('can.write:inventory.lots.manage')->name('articles.lots.store');

    Route::patch('lots/{lot}', [ArticleLotController::class, 'update'])
        ->middleware('can.write:inventory.lots.manage')->name('lots.update');

    // Acción propia y no un `PATCH` de estado: el lote deja de surtir, y eso merece su propia entrada en el
    // registro de lo que alguien hizo. NO registra la merma — el saldo sigue ahí hasta que alguien la registre
    // con su motivo, porque dar la mercancía por perdida sola convertiría un vencimiento de calendario en una
    // pérdida contable que nadie revisó.
    Route::post('lots/{lot}/expire', [ArticleLotController::class, 'expire'])
        ->middleware('can.write:inventory.lots.manage')->name('lots.expire');

    // ---- Mermas (D27) ----
    //
    // El catálogo de motivos comparte permiso con el registro, y es deliberado: quien registra mermas necesita
    // poder crear el motivo que le falta en el momento en que le falta. Obligarlo a pedirle a un gerente que dé de
    // alta «se cayó al piso» acabaría con todas las mermas bajo un motivo genérico — justo lo que D27 evita.
    //
    // Lo que sí está separado es AUTORIZAR sobre el umbral, y ése no es un endpoint: es una concesión de PIN
    // (ADR-008) que se pide en `POST /authorizations` y se manda en el cuerpo de la merma.
    Route::get('waste-reasons', [WasteController::class, 'reasons'])
        ->middleware('can:inventory.waste.create')->name('waste-reasons.index');

    Route::post('waste-reasons', [WasteController::class, 'storeReason'])
        ->middleware('can.write:inventory.waste.create')->name('waste-reasons.store');

    Route::patch('waste-reasons/{waste_reason}', [WasteController::class, 'updateReason'])
        ->middleware('can.write:inventory.waste.create')->name('waste-reasons.update');

    // Devuelve una LISTA por lo mismo que la salida manual: si el artículo lleva lotes, FEFO parte la merma y cada
    // renglón dice de qué partida física se perdió.
    //
    // Responde **409 `authorization_required`** cuando el monto pasa el umbral y no venía autorización: no es un
    // error de los datos, es la misma operación esperando la firma de otra persona.
    Route::post('waste', [WasteController::class, 'store'])
        ->middleware('can.write:inventory.waste.create')->name('waste.store');

    // ---- Movimientos manuales ----
    Route::post('stock-entries', [StockMovementController::class, 'storeEntry'])
        ->middleware('can.write:inventory.entries.create')->name('stock-entries.store');

    // Devuelve una LISTA de movimientos: cuando el artículo lleva lotes, la salida se parte por FEFO y cada
    // renglón dice de qué partida física salió.
    Route::post('stock-exits', [StockMovementController::class, 'storeExit'])
        ->middleware('can.write:inventory.exits.create')->name('stock-exits.store');

    // El ajuste es el único que exige dirección explícita y nota escrita: es la confesión de un descuadre, no
    // una operación del negocio.
    Route::post('stock-adjustments', [StockMovementController::class, 'storeAdjustment'])
        ->middleware('can.write:inventory.adjustments.create')->name('stock-adjustments.store');

    // ---- Conteos físicos (D24) ----
    //
    // La frontera entre los dos permisos es la regla de §6.2: **quien cuenta no decide que su conteo es la
    // verdad.** Abrir y capturar es `counts.create` —el almacenista—; cerrar y cancelar es `counts.close`.
    //
    // Y es la misma frontera del CONTEO CIEGO: con `counts.create` no se ven las cantidades esperadas ni las
    // diferencias, porque quien las ve escribe el número esperado en lugar de contar. Es el control que §6.3 ya
    // aplica al efectivo con el precorte ciego.
    //
    // Leer un conteo va con `counts.create` y no con un permiso de lectura propio: el almacenista tiene que poder
    // consultar su hoja de conteo mientras la captura. Lo que ve de ella lo decide el Resource.
    Route::get('stock-counts', [StockCountController::class, 'index'])
        ->middleware('can:inventory.counts.create')->name('stock-counts.index');

    Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])
        ->middleware('can:inventory.counts.create')->name('stock-counts.show');

    // Abrir CONGELA lo esperado. No hay estado borrador: congelar es lo primero que pasa, porque es el
    // equivalente a imprimir la hoja.
    Route::post('stock-counts', [StockCountController::class, 'store'])
        ->middleware('can.write:inventory.counts.create')->name('stock-counts.store');

    Route::put('stock-counts/{stockCount}/lines', [StockCountController::class, 'updateLines'])
        ->middleware('can.write:inventory.counts.create')->name('stock-counts.lines.update');

    // Cerrar aplica las diferencias al kardex de golpe, y responde **409 `authorization_required`** cuando la
    // diferencia valuada pasa el umbral: entonces hace falta el PIN del PROPIETARIO, no del gerente — es el
    // gerente quien cierra, y firmarse a sí mismo no sería un control.
    Route::post('stock-counts/{stockCount}/close', [StockCountController::class, 'close'])
        ->middleware('can.write:inventory.counts.close')->name('stock-counts.close');

    // Cancelar existe porque sólo cabe un conteo abierto por almacén: sin esta ruta, un conteo empezado por error
    // dejaría ese almacén sin poder contarse nunca. Descarta lo capturado sin aplicar nada, y va con el permiso de
    // cerrar — la misma autoridad que decide que un conteo es la verdad decide que no lo es.
    Route::post('stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel'])
        ->middleware('can.write:inventory.counts.close')->name('stock-counts.cancel');
});
