<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Shell de administración del catálogo (web)
|--------------------------------------------------------------------------
|
| Igual que el shell del kernel: estas rutas sólo entregan la página. Los datos vienen de
| `/api/v1` (D59), y la autorización la aplica cada endpoint — no estas rutas. Un usuario sin
| permiso ve la pantalla y recibe 403 al pedir los datos, y la navegación no le ofrece el enlace:
| los tres niveles de §4.2.
|
| ## Por qué el detalle del artículo recibe un parámetro
|
| `Admin/Catalog/Articles/Show` es la única página que recibe una prop, y es el ULID de la ruta.
| No es un dato de dominio —no trae nombre, precio ni costo, que se piden a la API—: es la
| respuesta a "¿de qué artículo estamos hablando?", que la página no puede adivinar. La
| alternativa sería leerlo de `window.location`, que es la misma cosa con más pasos y sin
| validación de forma.
|
| Las pantallas de costeo y receta NO tienen ruta propia: son paneles del detalle del artículo.
| Un costo se lee siempre en el contexto del artículo que cuesta eso, y una receta no existe sin
| su artículo (invariante I1). Darles URL propia habría creado pantallas huérfanas.
|
*/

Route::middleware(['auth'])->prefix('admin')->name('admin.catalog.')->group(function (): void {
    Route::get('articulos', fn () => Inertia::render('Admin/Catalog/Articles/Index'))->name('articles');

    // El ULID es de 26 caracteres de Crockford base32: la restricción evita que un enlace roto
    // monte la página para pedir después un artículo imposible.
    Route::get('articulos/{articulo}', fn (string $articulo) => Inertia::render(
        'Admin/Catalog/Articles/Show',
        ['articleUlid' => $articulo],
    ))->where('articulo', '[0-9A-HJKMNP-TV-Z]{26}')->name('articles.show');

    Route::get('categorias', fn () => Inertia::render('Admin/Catalog/Categories/Index'))->name('categories');
    Route::get('unidades', fn () => Inertia::render('Admin/Catalog/Units/Index'))->name('units');
    Route::get('etiquetas', fn () => Inertia::render('Admin/Catalog/Tags/Index'))->name('tags');
    Route::get('modificadores', fn () => Inertia::render('Admin/Catalog/ModifierGroups/Index'))->name('modifier-groups');
});
