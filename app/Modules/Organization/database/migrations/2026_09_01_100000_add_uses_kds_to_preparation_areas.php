<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El destino de las comandas de un área: pantalla (KDS) o no (D350).
 *
 * Un área de preparación siempre pudo recibir comandas por impresora; con el KDS (MVP acotado, D350) además puede
 * atenderse en una pantalla. Esta bandera marca cuáles. No sustituye al ruteo de comandas (D240): una comanda sigue
 * saliendo al área que le toca; `uses_kds` sólo decide si esa área ADEMÁS aparece como tablero.
 *
 * `default(false)`: encender el KDS es una decisión explícita del negocio, no algo que aparezca solo al actualizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_areas', function (Blueprint $table): void {
            $table->boolean('uses_kds')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('preparation_areas', function (Blueprint $table): void {
            $table->dropColumn('uses_kds');
        });
    }
};
