<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la FK que el paso 8 dejó pendiente: `supplier_prices.purchase_receipt_id`.
 *
 * La columna se creó en el paso 8 **sin constraint**, porque `purchase_receipts` todavía no existía. Quedaba como un
 * entero sin garantía: nada impedía escribir un identificador de recepción inexistente, y una observación de precio que
 * dice venir de una recepción que no existe es peor que una sin origen — parece verificable y no lo es.
 *
 * `RESTRICT` y no `CASCADE`: si se borrara la recepción, la observación de precio tiene que sobrevivir. El historial es
 * inmutable (§7) y perder filas por un borrado en cascada sería exactamente la clase de agujero que la inmutabilidad
 * existe para cerrar. En la práctica la recepción tampoco se borra —se reversa— así que RESTRICT describe lo que ya es
 * cierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_prices', function (Blueprint $table): void {
            $table->foreign('purchase_receipt_id', 'supplier_prices_receipt_foreign')
                ->references('id')->on('purchase_receipts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_prices', function (Blueprint $table): void {
            $table->dropForeign('supplier_prices_receipt_foreign');
        });
    }
};
