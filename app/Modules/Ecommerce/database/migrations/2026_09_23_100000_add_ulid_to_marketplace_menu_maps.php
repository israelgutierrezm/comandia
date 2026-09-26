<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ULID público para el mapeo de menú de marketplace (ADR-015, Fase 2).
 *
 * En la Fase 1 el mapeo sólo lo leía la ingesta, sin API. Ahora el admin lo gestiona por API —lista, alta
 * y baja— y la regla del proyecto es no exponer ids secuenciales: se agrega el `ulid`. Las filas que ya
 * existieran se rellenan antes de volverlo obligatorio y único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_menu_maps', function (Blueprint $table): void {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable()->after('id');
        });

        DB::table('marketplace_menu_maps')->whereNull('ulid')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('marketplace_menu_maps')
                ->where('id', $id)
                ->update(['ulid' => Str::upper((string) Str::ulid())]));

        Schema::table('marketplace_menu_maps', function (Blueprint $table): void {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable(false)->change();
            $table->unique('ulid', 'marketplace_menu_maps_ulid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_menu_maps', function (Blueprint $table): void {
            $table->dropUnique('marketplace_menu_maps_ulid_unique');
            $table->dropColumn('ulid');
        });
    }
};
