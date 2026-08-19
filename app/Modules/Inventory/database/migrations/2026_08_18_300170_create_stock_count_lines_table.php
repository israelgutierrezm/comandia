<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_count_lines` — un renglón de la hoja de conteo.
 *
 * ## `expected_quantity` se CONGELA, y es toda la diferencia entre un conteo y una foto borrosa
 *
 * No se lee al cerrar. Si se leyera, cualquier venta ocurrida mientras la gente caminaba por el almacén
 * cambiaría la diferencia, y el resultado del conteo dependería de cuánto tardó quien contaba — dos personas
 * contando lo mismo con distinta prisa llegarían a distinta diferencia. Congelado, la diferencia significa
 * exactamente «entre lo que el sistema creía en el instante T y lo que había en el estante».
 *
 * Consecuencia que hay que aceptar: los movimientos ocurridos durante el conteo **no** se descuentan de la
 * diferencia. Es correcto y es lo que §6.2 pide — el conteo no bloquea el almacén, y a cambio la diferencia
 * incluye el movimiento del periodo. En un conteo nocturno, que es cuando se hacen, el periodo es corto.
 *
 * ## `counted_quantity` NULL no es cero
 *
 * `NULL` significa **«no se contó»**; cero significa «se contó y no había». La distinción es crítica: si `NULL`
 * se tratara como cero, cerrar un conteo con la mitad de la hoja en blanco borraría medio almacén. Al cerrar,
 * las líneas sin contar no generan ningún ajuste.
 *
 * Por eso `variance` es una columna generada: cuando `counted_quantity` es `NULL`, la diferencia es `NULL`
 * también, y «sin diferencia que aplicar» queda expresado por la estructura y no por un `if` que alguien pueda
 * olvidar en el segundo camino.
 *
 * ## `unit_cost_at_count` también se congela
 *
 * Y se usa **el mismo valor** para tres cosas: para valuar la diferencia en el reporte, para compararla con el
 * umbral de autorización y para el movimiento de ajuste que se escribe al cerrar. Si fueran tres lecturas del
 * costo vigente, un cambio de costo entre la captura y el cierre autorizaría por una cifra y registraría otra
 * — la misma razón por la que las mermas extrajeron `ResolveArticleCost` (D169).
 *
 * ## Sin `ulid`
 *
 * Una línea nunca se direcciona por sí sola. La captura es un `PUT` masivo identificado por artículo y lote,
 * porque así llega la hoja de papel: una lista, no cien peticiones. Darle ULID sería prometer un endpoint por
 * línea que no existe ni conviene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: una línea no tiene vida sin su conteo. Y no colisiona con la columna generada de esta
            // tabla (D156) porque ésa se basa en `lot_id`, no en esta columna.
            $table->foreignId('stock_count_id')
                ->constrained('stock_counts')
                ->cascadeOnDelete();

            // RESTRICT las dos: la línea es evidencia de un conteo cerrado. Y `lot_id` tiene además la razón
            // de MySQL —la columna generada se basa en ella (D156).
            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            // Firmada: el saldo esperado puede ser negativo (§6.2), y de hecho un negativo es el caso que más
            // urge contar.
            $table->decimal('expected_quantity', 12, 4);

            // NULL = no se contó. Sin `default`, a propósito: un default de cero es exactamente el error que
            // borraría medio almacén.
            $table->decimal('counted_quantity', 12, 4)->nullable();

            $table->decimal('unit_cost_at_count', 12, 4)->nullable();

            // El movimiento de ajuste que esta línea generó al cerrar. Hace dos cosas: permite ir del renglón
            // del conteo al renglón del kardex, y hace **detectable** un cierre a medias — una línea con
            // diferencia y sin movimiento es un cierre que se interrumpió.
            $table->foreignId('adjustment_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            // Sin índice propio: la única consulta es «las líneas de este conteo», y el índice único de abajo
            // empieza por (tenant_id, stock_count_id), que la sirve. Un índice más sería un índice sin
            // justificación (§7).
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_count_lines`
            ADD COLUMN `lot_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (COALESCE(`lot_id`, 0)) STORED
        SQL);

        // `variance` generada: cantidad contada menos esperada. NULL cuando no se contó, que es justo lo que
        // «no hay nada que aplicar» significa.
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_count_lines`
            ADD COLUMN `variance` DECIMAL(12,4)
                GENERATED ALWAYS AS (`counted_quantity` - `expected_quantity`) STORED
        SQL);

        // Un renglón por (conteo, artículo, lote). Dos renglones del mismo artículo en la misma hoja serían dos
        // conteos distintos de la misma cosa, y al cerrar se aplicarían los dos.
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_count_lines`
            ADD UNIQUE `stock_count_lines_unique` (`tenant_id`, `stock_count_id`, `article_id`, `lot_key`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
    }
};
