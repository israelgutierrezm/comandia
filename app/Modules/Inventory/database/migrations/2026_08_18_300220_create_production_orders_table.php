<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `production_orders` — producir un artículo producible consumiendo su receta (D17, P8).
 *
 * Es el documento que conecta esta iteración con el costeo de la anterior: la receta dice qué se consume, el costeo
 * dice cuánto vale, y aquí eso se convierte en movimientos de kardex reales.
 *
 * ## Documento y no movimiento con receta (P8)
 *
 * Un movimiento suelto no puede guardar **qué se consumió**. Producir veinte litros de salsa son seis salidas y una
 * entrada, y sin un documento que las relacione, cancelar una producción sería perseguir siete movimientos a mano y
 * la pregunta «¿de dónde salieron estos veinte litros?» no tendría respuesta.
 *
 * ## Dos cantidades: la planeada y la producida
 *
 * Se planean veinte litros y salen dieciocho. Es la misma razón por la que la transferencia guarda tres (D187): sin
 * las dos, no se puede distinguir «se planeó de más» de «rindió menos», y son problemas distintos — uno es de
 * planeación y el otro de la receta o del proceso.
 *
 * `produced_quantity` es NULL mientras la orden está en borrador: «todavía no se produjo», que no es cero.
 *
 * ## `recipe_id` NO es un snapshot, y el diseño creía que sí
 *
 * El §2.8 proponía `recipe_snapshot_id → recipes` «porque las recetas cambian: sin él, un lote producido en marzo se
 * explicaría con la receta de agosto». El razonamiento es correcto y la solución no funciona: `recipes` es **una por
 * artículo y mutable**, sin versiones, así que la llave apunta a una fila cuyas líneas pueden cambiar mañana. Guardar
 * el `recipe_id` no congela nada.
 *
 * El snapshot de verdad está en `production_order_lines`, que guarda lo que se consumió con su cantidad, su costo y
 * el rendimiento aplicado. `recipe_id` se conserva —dice de qué receta salió— pero como referencia, no como prueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT: una orden completada es evidencia de siete movimientos de kardex.
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // La receta de la que salió. Nulable porque una orden cancelada pudo crearse y la receta borrarse
            // después; y RESTRICT no sirve aquí —bloquearía borrar una receta por una orden vieja— mientras el
            // snapshot real, que son las líneas, sobrevive de todos modos.
            $table->foreignId('recipe_id')
                ->nullable()
                ->constrained('recipes')
                ->nullOnDelete();

            $table->string('status', 20);

            // Las dos en la unidad BASE del producible, como todo el kardex.
            $table->decimal('planned_quantity', 12, 4);
            $table->decimal('produced_quantity', 12, 4)->nullable();

            // El costo unitario con el que entró el producible, congelado. Es la cifra que explica el valor de esos
            // veinte litros, y recalcularla el mes que viene daría otra: los costos de los insumos cambian.
            $table->decimal('unit_cost_at_production', 12, 4)->nullable();

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->foreignId('produced_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('produced_at')->nullable();

            $table->string('notes', 300)->nullable();

            $table->timestamps();

            // «Qué se produjo en este almacén, de lo más reciente a lo más viejo»: la pantalla de la sección.
            $table->index(['tenant_id', 'warehouse_id', 'created_at'], 'production_orders_tenant_warehouse_index');

            // «Qué está planeado», que es la consulta con la que se abre el día y la que alimenta la compra.
            $table->index(['tenant_id', 'status', 'created_at'], 'production_orders_tenant_status_index');

            // «Cuántas veces y cuánto he producido de este artículo»: es el histórico de rendimiento de una receta,
            // y sin él habría que recorrer todas las órdenes del negocio para contestarlo.
            $table->index(['tenant_id', 'article_id', 'created_at'], 'production_orders_tenant_article_index');
        });

        // Producir cero o menos no es producir. Es un CHECK y no una validación porque una orden de cero no es un
        // error recuperable: no produciría ningún movimiento y sería un documento que no significa nada.
        DB::statement(<<<'SQL'
            ALTER TABLE `production_orders`
            ADD CONSTRAINT `chk_production_orders_positive_plan` CHECK (`planned_quantity` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
