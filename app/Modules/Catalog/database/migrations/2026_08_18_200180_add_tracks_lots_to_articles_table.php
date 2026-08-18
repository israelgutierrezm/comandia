<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `articles.tracks_lots` — lotes y caducidades **opcionales por artículo** (D23).
 *
 * ## Por qué la migración vive en `Catalog` y no en `Inventory`
 *
 * La columna la pide `Inventory` —es él quien elige lotes al dar salida— pero la tabla es de `Catalog`, y cada
 * módulo es dueño de sus tablas (§2). Una migración de `Inventory` alterando `articles` rompería esa propiedad
 * y dejaría el esquema de un módulo repartido entre dos carpetas.
 *
 * Y además la columna **es** del artículo: dice qué clase de cosa es, igual que las cuatro capacidades de D17.
 * El jitomate no lleva lotes; la leche sí, y eso no depende de quién lo pregunte.
 *
 * ## Por omisión, NO
 *
 * §6.2 dice «lotes/caducidades opcionales por artículo», y el valor por omisión es la respuesta a qué pasa con
 * los cientos de artículos que ya existen. `false` es lo correcto: activar lotes obliga a capturar el lote en
 * cada recepción y en cada salida manual, y encenderlo para todo el catálogo de golpe volvería el inventario
 * impracticable en un negocio que hasta ayer no los usaba.
 *
 * ## Sin índice
 *
 * «Los artículos que llevan lotes» no es una consulta del sistema: cuando se pregunta, ya se tiene el artículo
 * en la mano —se está dando entrada o salida a ÉSE— y la lectura es de una sola fila por su llave. Un índice
 * sobre un booleano de baja selectividad ocuparía espacio y no lo usaría nadie (§7: ningún índice sin
 * justificación escrita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->boolean('tracks_lots')->default(false)->after('is_producible');
        });

        // Sólo lo inventariable puede llevar lotes: un lote es una partida física con caducidad, y un artículo
        // del que no se controlan existencias no tiene partidas que rastrear.
        //
        // El CHECK lo hace estructural en lugar de dejarlo a la validación, por lo mismo que el resto de los
        // invariantes del catálogo: un seeder o una importación tampoco pueden crear la contradicción.
        DB::statement(<<<'SQL'
            ALTER TABLE `articles`
            ADD CONSTRAINT `chk_articles_lots_require_inventory`
            CHECK (`tracks_lots` = 0 OR `is_inventoriable` = 1)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `articles` DROP CONSTRAINT `chk_articles_lots_require_inventory`');

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('tracks_lots');
        });
    }
};
