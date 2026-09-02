<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Agotado» de un modificador (86'ing durante el servicio) — punto 4.
 *
 * Es TEMPORAL y distinto de `status`: `status=inactive` retira el modificador de la carta (permanente, decisión de
 * catálogo); `sold_out` dice «hoy se acabó», lo marca la cocina/gerente en el servicio y se vuelve a poner al reponer.
 * En el POS un modificador agotado se ve deshabilitado; la captura lo rechaza aunque siga `active`.
 *
 * `default(false)`: nada nace agotado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifiers', function (Blueprint $table): void {
            $table->boolean('sold_out')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('modifiers', function (Blueprint $table): void {
            $table->dropColumn('sold_out');
        });
    }
};
