<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota libre a cocina en la línea de la orden (punto 4).
 *
 * «Sin picante», «bien cocido», «para el niño»: instrucciones que no son un modificador con precio pero que la cocina
 * necesita. Se congela en la línea como el resto (nombre, precio, modificadores) y viaja en la comanda/KDS. Nullable
 * porque la mayoría de las líneas no la lleva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->string('note', 255)->nullable()->after('article_name');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
