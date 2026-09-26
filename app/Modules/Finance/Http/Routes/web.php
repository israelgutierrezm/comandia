<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Finanzas (web)
|--------------------------------------------------------------------------
|
| Igual que el resto del shell: estas rutas sólo entregan la página. Los datos vienen de `/api/v1` (D59) y la
| autorización la aplica cada endpoint —ver `api.php` de este módulo, que es la respuesta completa a «¿qué permiso hace
| falta?»—, no estas rutas. Una pantalla abierta sin el permiso de su lista no filtra nada: la API responde 403 y la
| página lo dice. El guard de navegación del shell oculta el enlace a quien no tiene el permiso.
|
| Cinco pantallas porque son cinco preguntas distintas: con qué se cobra (métodos de pago), en qué se fue el dinero
| (gastos y sus categorías), a dónde fue el efectivo retirado (depósitos), a quién se le debe propina (propinas) y qué
| dice el diario (movimientos, sólo lectura).
|
*/

Route::middleware(['auth'])->prefix('admin/finanzas')->name('admin.finance.')->group(function (): void {
    Route::get('metodos-de-pago', fn () => Inertia::render('Admin/Finance/PaymentMethods'))->name('payment-methods');

    // Los gastos y su catálogo de categorías comparten pantalla: quien registra necesita la categoría que le falta en el
    // momento en que le falta, que es el mismo criterio de las mermas y sus motivos (D171).
    Route::get('gastos', fn () => Inertia::render('Admin/Finance/Expenses'))->name('expenses');

    Route::get('depositos', fn () => Inertia::render('Admin/Finance/Deposits'))->name('deposits');

    Route::get('propinas', fn () => Inertia::render('Admin/Finance/Tips'))->name('tips');

    // El diario: SÓLO LECTURA, como su API. Al diario escriben únicamente los oyentes de eventos de dominio (ADR-004).
    Route::get('movimientos', fn () => Inertia::render('Admin/Finance/Journal'))->name('journal');
});
