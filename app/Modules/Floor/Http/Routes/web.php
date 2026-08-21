<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Editor del salón (web)
|--------------------------------------------------------------------------
|
| Igual que el resto del shell: la ruta sólo entrega la página, y la autorización la aplica cada endpoint de la API
| (D59). Alguien sin `floor.layouts.edit` entra al editor y no puede guardar; el botón se lo dice antes de intentarlo,
| pero quien decide es el servidor.
|
| ## Por qué el editor vive en `Floor` y el piso en `Pos`
|
| Dibujar el salón no necesita saber quién está sentado. El piso de venta sí —junta la geometría con la cuenta que
| ocupa cada mesa— y la dirección permitida es `Pos → Floor`, así que el que junta tiene que ser el POS (D299).
|
| El plano puede venir en la URL para poder compartir un enlace a un salón concreto; sin él, se abre el de omisión de
| la sucursal activa.
|
*/

Route::middleware(['auth'])->prefix('admin/piso')->name('admin.floor.')->group(function (): void {
    Route::get('editor', fn () => Inertia::render('Admin/Floor/Editor'))->name('editor');

    Route::get('editor/{plan}', fn (string $plan) => Inertia::render(
        'Admin/Floor/Editor',
        ['planUlid' => $plan],
    ))->where('plan', '[0-9A-HJKMNP-TV-Z]{26}')->name('editor.plan');
});
