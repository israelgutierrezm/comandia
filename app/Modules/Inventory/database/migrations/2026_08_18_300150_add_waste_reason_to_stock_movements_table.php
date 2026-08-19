<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_movements.waste_reason_id` — la merma NO es una tabla propia (D27, §6.2).
 *
 * ## Por qué la merma es un movimiento con motivo y no un documento
 *
 * Un documento de merma aparte tendría que guardar su propia cantidad y su propio costo, y esa duplicación es
 * exactamente de donde salen los descuadres entre «reporte de mermas del mes» y kardex: dos cifras que deberían
 * ser la misma, mantenidas por dos caminos distintos.
 *
 * Con el motivo en el movimiento, el reporte de mermas es un **filtro** —`kind = 'waste'` agrupado por motivo—
 * servido por el índice que ya existe. Una sola verdad y ninguna suma que cuadrar.
 *
 * ## Sobre agregar una columna al kardex
 *
 * Inmutable se refiere a las **filas**, no al esquema (D151): agregar una columna no modifica ningún movimiento
 * registrado. Los movimientos anteriores quedan con `NULL`, que es correcto — no eran mermas.
 *
 * ## Sin CHECK que ate el motivo al tipo
 *
 * La regla «sólo una merma lleva motivo, y toda merma lo lleva» vive en el dominio y no en un CHECK, y es una
 * decisión: un CHECK sobre dos columnas del kardex tendría que reescribirse cada vez que el enum de tipos crezca
 * —ya pasó una vez en el paso 2— y el beneficio sería redundante con una validación que el servicio ya impone
 * antes de escribir. El CHECK de dirección sí existe porque protege de una contradicción que **ningún** camino
 * debería poder crear, ni un seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            // RESTRICT: un motivo citado por mermas no puede desaparecer. Los motivos se dan de baja —dejan de
            // ofrecerse al capturar— y el histórico sigue diciendo por qué se perdió aquella mercancía.
            $table->foreignId('waste_reason_id')
                ->nullable()
                ->after('lot_id')
                ->constrained('waste_reasons')
                ->restrictOnDelete();
        });

        // El reporte que D27 hace posible: «las mermas del mes, agrupadas por motivo». Sin índice sería un
        // recorrido de la tabla más grande del sistema filtrando por una columna casi siempre nula.
        //
        // Empieza por `tenant_id` como toda tabla transaccional (§7) y termina en la fecha para que el rango
        // salga del índice.
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD INDEX `stock_movements_waste_reason_index` (`tenant_id`, `waste_reason_id`, `occurred_at`)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `stock_movements` DROP INDEX `stock_movements_waste_reason_index`');

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waste_reason_id');
        });
    }
};
