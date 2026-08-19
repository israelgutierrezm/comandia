<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `production_order_lines` — **el snapshot de verdad de la receta usada.**
 *
 * El §2.8 quería congelar la receta con una llave a `recipes` y no alcanza: esa tabla es una fila por artículo y
 * mutable, así que la llave apunta a algo que cambia. Estas líneas son el congelamiento real — se escriben al
 * **completar** la orden y guardan lo que de verdad se consumió.
 *
 * Cada línea conserva los cuatro datos con los que se puede reconstruir el cálculo sin la receta:
 *
 *   - `recipe_quantity` y `recipe_unit_id`: la cantidad **como estaba escrita en la receta**, en su unidad.
 *   - `yield_percent`: el rendimiento aplicado (D21).
 *   - `consumed_quantity`: lo que salió del almacén, en la unidad BASE del componente.
 *   - `unit_cost_at_production`: con qué costo salió.
 *
 * Sin los dos primeros, el documento diría cuánto se consumió pero no **por qué esa cantidad**, y quien revisara un
 * consumo raro no podría saber si la receta pedía de más o si alguien la cambió después.
 *
 * ## Por qué se escriben al completar y no al planear
 *
 * Porque el momento del hecho físico es la producción, y la receta que vale es la que estaba en vigor entonces. Si se
 * congelaran al planear, una orden que se queda tres días en borrador produciría con la receta de anteayer, ignorando
 * una corrección hecha ayer — y nadie lo notaría.
 *
 * La contrapartida es que un borrador no tiene líneas que mostrar. Se resuelve sin persistir nada: la previsualización
 * de «qué va a consumir esto» se calcula del desglose de costeo, que ya existe y ya sabe convertir unidades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: una línea no tiene vida sin su orden. No colisiona con la columna generada (D156) porque ésa
            // se basa en `lot_id`.
            $table->foreignId('production_order_id')
                ->constrained('production_orders')
                ->cascadeOnDelete();

            $table->foreignId('component_article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // El lote del que salió, cuando el insumo lleva lotes. Lo elige FEFO, y por eso una misma receta puede
            // producir dos líneas del mismo componente: la salida se partió entre dos partidas físicas.
            //
            // RESTRICT, y además por exigencia de MySQL: la columna generada se basa en ella (D156).
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            // El snapshot de la receta: la cantidad tal como estaba escrita, con su unidad y su rendimiento.
            $table->decimal('recipe_quantity', 12, 4);

            $table->foreignId('recipe_unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            $table->decimal('yield_percent', 5, 2);

            // Lo que de verdad salió del almacén, en la unidad base del componente.
            $table->decimal('consumed_quantity', 12, 4);

            $table->decimal('unit_cost_at_production', 12, 4)->nullable();

            // El movimiento de salida que esta línea produjo. Hace navegable orden → kardex y vuelve DETECTABLE una
            // producción a medias: una línea sin movimiento es una producción que se interrumpió.
            $table->foreignId('movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            // Sin índice propio: la única consulta es «las líneas de esta orden», y el índice único de abajo empieza
            // por (tenant_id, production_order_id) y la sirve (§7).
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `production_order_lines`
            ADD COLUMN `lot_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (COALESCE(`lot_id`, 0)) STORED
        SQL);

        // Un renglón por (orden, componente, lote). El lote entra en la llave justamente porque FEFO puede partir el
        // consumo de un componente entre dos partidas, y las dos son renglones legítimos de la misma orden.
        DB::statement(<<<'SQL'
            ALTER TABLE `production_order_lines`
            ADD UNIQUE `production_order_lines_unique`
                (`tenant_id`, `production_order_id`, `component_article_id`, `lot_key`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_order_lines');
    }
};
