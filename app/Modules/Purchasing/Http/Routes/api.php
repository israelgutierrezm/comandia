<?php

declare(strict_types=1);

use App\Modules\Purchasing\Http\Controllers\PurchaseReceiptController;
use App\Modules\Purchasing\Http\Controllers\SupplierController;
use App\Modules\Purchasing\Http\Controllers\SupplierPriceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del módulo Purchasing — /api/v1
|--------------------------------------------------------------------------
|
| Los permisos van en la ruta y no en el controlador: así este archivo es la respuesta completa a «¿qué permiso hace
| falta para esto?», que es la pregunta que se hace al auditar.
|
| ## Sin borrado, ni de proveedores ni de precios
|
| Un proveedor se da de BAJA: sus recepciones y su historial lo citan, y borrarlo dejaría compras sin poder decir a
| quién se le compraron. Un precio no se borra ni se edita porque el historial es inmutable (§7) — se corrige agregando
| otra observación, ya que si el precio se capturó mal, lo cierto es que hubo un error de captura ese día.
|
| ## Capturar y CONFIRMAR son permisos distintos
|
| `purchasing.receipts.create` captura y cancela borradores; `purchasing.receipts.confirm` —el permiso que D153 dejó
| comprometido para este paso— aplica la recepción al inventario y al historial de costos.
|
| La frontera es lo que mueve existencia, igual que en el conteo físico (D179): quien recibe la mercancía captura la
| factura, y aplicarla es de quien responde por el inventario. Sin la separación, quien captura podría fijar el costo de
| cualquier artículo — y de ahí salen todos los precios sugeridos y todos los márgenes.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {

    // ---- Proveedores (D26) ----
    Route::get('suppliers', [SupplierController::class, 'index'])
        ->middleware('can:purchasing.suppliers.view')->name('suppliers.index');

    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
        ->middleware('can:purchasing.suppliers.view')->name('suppliers.show');

    Route::post('suppliers', [SupplierController::class, 'store'])
        ->middleware('can.write:purchasing.suppliers.manage')->name('suppliers.store');

    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])
        ->middleware('can.write:purchasing.suppliers.manage')->name('suppliers.update');

    // ---- Historial de precios de proveedor (D26) ----
    //
    // Se LEE con su permiso propio, `purchasing.supplier_prices.view`, y lo tiene el almacenista: recibe la mercancía
    // con la factura en la mano, así que necesita poder comparar lo que le están cobrando (D161).
    //
    // Se CAPTURA con `purchasing.suppliers.manage`, que es más restringido. La diferencia no es descuido: registrar
    // una cotización es tomar una posición sobre a quién comprarle, y eso es decisión de quien negocia. El catálogo
    // cerrado no tiene un permiso propio para esto y no se agrega uno — a diferencia de los motivos de merma (D171),
    // aquí quien captura NO es quien necesita el dato en el momento.
    Route::get('suppliers/{supplier}/prices', [SupplierPriceController::class, 'forSupplier'])
        ->middleware('can:purchasing.supplier_prices.view')->name('suppliers.prices.index');

    Route::post('suppliers/{supplier}/prices', [SupplierPriceController::class, 'store'])
        ->middleware('can.write:purchasing.suppliers.manage')->name('suppliers.prices.store');

    // LA consulta de la tabla: «¿quién me lo vende más barato y quién me subió el precio?». Es la razón por la que el
    // historial es un historial y no un precio vigente — con una sola fila por proveedor, la segunda mitad de la
    // pregunta no tiene respuesta.
    Route::get('articles/{article}/supplier-prices', [SupplierPriceController::class, 'forArticle'])
        ->middleware('can:purchasing.supplier_prices.view')->name('articles.supplier-prices');

    // El catálogo de orígenes, para que el cliente no escriba las etiquetas a mano (D139).
    Route::get('supplier-price-sources', [SupplierPriceController::class, 'sources'])
        ->middleware('can:purchasing.supplier_prices.view')->name('supplier-price-sources.index');

    // ---- Recepciones de compra (D26, §3.2) ----
    //
    // Sin orden de compra en v1: es la simplificación declarada de D26, y su deuda es que no se puede comparar lo
    // pedido con lo recibido hasta que exista el documento del pedido.
    Route::get('purchase-receipts', [PurchaseReceiptController::class, 'index'])
        ->middleware('can:purchasing.receipts.create')->name('purchase-receipts.index');

    Route::get('purchase-receipts/{purchaseReceipt}', [PurchaseReceiptController::class, 'show'])
        ->middleware('can:purchasing.receipts.create')->name('purchase-receipts.show');

    // Capturar NO mueve nada: el borrador existe para cuadrar los totales con el papel antes de aplicar.
    Route::post('purchase-receipts', [PurchaseReceiptController::class, 'store'])
        ->middleware('can.write:purchasing.receipts.create')->name('purchase-receipts.store');

    // Cancelar es sólo para borradores, y va con el permiso de capturar: quien se equivocó al teclear puede desdecirse
    // mientras nada se haya movido.
    Route::post('purchase-receipts/{purchaseReceipt}/cancel', [PurchaseReceiptController::class, 'cancel'])
        ->middleware('can.write:purchasing.receipts.create')->name('purchase-receipts.cancel');

    // CONFIRMAR aplica todo de golpe, y por evento: `Inventory` registra los movimientos creando los lotes que hagan
    // falta, `Costing` captura el costo con `origin = purchase`, y este módulo deja la observación de precio.
    Route::post('purchase-receipts/{purchaseReceipt}/confirm', [PurchaseReceiptController::class, 'confirm'])
        ->middleware('can.write:purchasing.receipts.confirm')->name('purchase-receipts.confirm');

    // REVERSAR crea un documento nuevo que señala al original, y lo confirma en el mismo paso. La original no se toca
    // ni para marcarla: la corrección es un registro nuevo, igual que en el kardex.
    Route::post('purchase-receipts/{purchaseReceipt}/reverse', [PurchaseReceiptController::class, 'reverse'])
        ->middleware('can.write:purchasing.receipts.confirm')->name('purchase-receipts.reverse');
});
