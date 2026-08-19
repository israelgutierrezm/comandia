<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `transfer_lines` — un renglón de la transferencia, con sus TRES cantidades.
 *
 * ## Tres cantidades y no una
 *
 * `requested`, `shipped` y `received` son lo que permite contestar **«¿se pidió poco, se mandó poco o se perdió en
 * el camino?»**. Con una sola cantidad esa pregunta no tiene respuesta, y es la pregunta por la que existe el
 * documento: las tres respuestas exigen acciones distintas —pedir mejor, surtir mejor, o averiguar qué pasó en el
 * camión— y confundirlas hace que nadie corrija nada.
 *
 * `shipped` y `received` son nulables porque el paso todavía no ocurrió. `NULL` es «no ha pasado»; cero es «se
 * decidió no mandar nada» o «no llegó nada», que son hechos distintos y con consecuencias distintas — la misma
 * distinción que en el conteo físico (D177), y por la misma razón.
 *
 * ## Sin `ulid`
 *
 * Igual que las líneas de conteo: una línea nunca se direcciona sola. El envío y la recepción llegan como listas
 * identificadas por artículo y lote, porque así se trabaja con una hoja de embarque en la mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: una línea no tiene vida sin su transferencia. No colisiona con la columna generada de esta
            // tabla (D156) porque ésa se basa en `lot_id`.
            $table->foreignId('transfer_id')
                ->constrained('transfers')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // RESTRICT, y además por exigencia de MySQL: la columna generada se basa en ella (D156).
            //
            // El lote viaja en la línea porque la caducidad viaja con la mercancía: mandar leche del lote de marzo
            // y que en destino aparezca como del lote de abril haría que FEFO surtiera en el orden equivocado.
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            $table->decimal('requested_quantity', 12, 4);
            $table->decimal('shipped_quantity', 12, 4)->nullable();
            $table->decimal('received_quantity', 12, 4)->nullable();

            $table->timestamps();

            // Sin índice propio: la única consulta es «las líneas de esta transferencia», y el índice único de
            // abajo empieza por (tenant_id, transfer_id) y la sirve. Uno más sería un índice sin justificación (§7).
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `transfer_lines`
            ADD COLUMN `lot_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (COALESCE(`lot_id`, 0)) STORED
        SQL);

        // La diferencia en tránsito: lo que salió menos lo que llegó. Generada por la base y no calculada al
        // recibir, por lo mismo que en el conteo (D177): con `received_quantity` en `NULL` la diferencia es `NULL`,
        // y «todavía no se sabe» queda dicho por la estructura en lugar de por un `if` que alguien pueda olvidar.
        DB::statement(<<<'SQL'
            ALTER TABLE `transfer_lines`
            ADD COLUMN `transit_difference` DECIMAL(12,4)
                GENERATED ALWAYS AS (`shipped_quantity` - `received_quantity`) STORED
        SQL);

        // Un renglón por (transferencia, artículo, lote). Dos del mismo artículo serían dos cantidades pedidas de
        // la misma cosa, y al enviar se moverían las dos.
        DB::statement(<<<'SQL'
            ALTER TABLE `transfer_lines`
            ADD UNIQUE `transfer_lines_unique` (`tenant_id`, `transfer_id`, `article_id`, `lot_key`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_lines');
    }
};
