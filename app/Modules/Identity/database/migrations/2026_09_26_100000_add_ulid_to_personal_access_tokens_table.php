<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `personal_access_tokens.ulid`: el identificador público de una sesión de la app (diseño de acceso, fase 1).
 *
 * «Mis dispositivos» y la ficha de una persona cierran sesiones de la app una por una, así que la API tiene que nombrar
 * cada token — y un id secuencial no se expone nunca (§7 de la arquitectura). El token de Sanctum ya lleva su id dentro
 * del texto que recibe la app (`{id}|{secreto}`); eso es de Sanctum y no sale por ninguna respuesta de Comandia.
 *
 * Índice `unique(ulid)`: revocar busca por él, y es la unicidad del identificador público. Sin tenant al frente porque
 * esta tabla no lleva el global scope de tenant (el token ES el origen del contexto, ver el modelo) y la búsqueda por
 * ULID ya es única en toda la tabla.
 *
 * Los tokens que ya existen reciben su ULID aquí; por eso la columna nace nullable y se cierra al final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable()->after('id');
        });

        DB::table('personal_access_tokens')->whereNull('ulid')->orderBy('id')->select('id')->chunkById(500, function ($tokens): void {
            foreach ($tokens as $token) {
                DB::table('personal_access_tokens')
                    ->where('id', $token->id)
                    ->update(['ulid' => Str::upper((string) Str::ulid())]);
            }
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable(false)->change();
            $table->unique('ulid', 'personal_access_tokens_ulid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropUnique('personal_access_tokens_ulid_unique');
            $table->dropColumn('ulid');
        });
    }
};
