<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `recipe_lines` — los componentes de una receta y su rendimiento (D21).
 *
 * ## `yield_percent` DIVIDE, no multiplica
 *
 * Si la receta pide 200 g de cebolla **utilizable** y el rendimiento es 80 %, hay que comprar 250 g:
 *
 *     costo_línea = costo_por_unidad_base × cantidad ÷ (yield_percent / 100)
 *
 * Es el sentido que la operación exige —la merma de limpieza la paga el platillo— y equivocar la
 * dirección **subvalúa sistemáticamente todos los costos del catálogo**, siempre en el mismo sentido,
 * con lo que el margen que el sistema reporta sale optimista. Tiene prueba propia por eso.
 *
 * ## Esta tabla es el grafo de composición
 *
 * Cada fila es una arista "el artículo dueño de esta receta usa este componente". El grafo tiene que
 * ser acíclico y eso **no lo puede garantizar la base de datos**: un ciclo A→B→A son dos filas
 * perfectamente válidas por separado. Lo garantiza la detección de ciclos antes de escribir (D16), y
 * el motivo de que sea obligatoria es concreto: un ciclo guardado hace que el recálculo de costos no
 * termine nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('recipe_id')
                ->constrained('recipes')
                ->cascadeOnDelete();

            // RESTRICT: un insumo usado en recetas no se borra. Se archiva (D80). Borrarlo dejaría
            // recetas incompletas y costos que dejarían de poder recalcularse.
            $table->foreignId('component_article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // Cantidad en la unidad de la línea, no en la del componente: la receta se captura como se
            // cocina ("250 ml de crema"), y la conversión a la unidad base del componente la hace el
            // motor de costeo.
            $table->decimal('quantity', 12, 4)->unsigned();

            $table->foreignId('unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            $table->decimal('yield_percent', 5, 2)->unsigned()->default(100.00);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // El mismo insumo dos veces en una receta son dos cantidades que alguien sumará mal. Se
            // captura una línea con la cantidad total.
            $table->unique(
                ['recipe_id', 'component_article_id'],
                'recipe_lines_recipe_component_unique'
            );

            // EL ÍNDICE MÁS IMPORTANTE DE LA ITERACIÓN.
            //
            // Es la dirección inversa del grafo —"¿qué recetas usan este insumo?"— y la recorren dos
            // cosas: la detección de ciclos y el recálculo transitivo cuando cambia un costo. Sin él,
            // cada cambio de costo de un insumo recorre la tabla completa de líneas de receta, y en un
            // negocio con 800 artículos eso ocurre en cada recepción de compra.
            $table->index(
                ['tenant_id', 'component_article_id'],
                'recipe_lines_tenant_component_index'
            );
        });

        // Un rendimiento de 0 sería una división por cero —costo infinito— y uno mayor que 100
        // significaría que del insumo sale más de lo que entró.
        DB::statement(<<<'SQL'
            ALTER TABLE `recipe_lines`
            ADD CONSTRAINT `chk_recipe_lines_yield_range` CHECK (
                `yield_percent` > 0 AND `yield_percent` <= 100
            )
        SQL);

        // Una cantidad de 0 es una línea que no aporta nada al costo y que alguien capturó por error.
        DB::statement(<<<'SQL'
            ALTER TABLE `recipe_lines`
            ADD CONSTRAINT `chk_recipe_lines_quantity_positive` CHECK (`quantity` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
