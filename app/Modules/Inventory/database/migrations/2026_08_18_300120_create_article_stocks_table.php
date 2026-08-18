<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_stocks` — la PROYECCIÓN del saldo. La verdad está en el kardex.
 *
 * Existe por la misma razón que `article_current_costs` en el costeo (D94): la pregunta «¿cuánto tengo?» se
 * hace mil veces al día —en cada pantalla del POS, en cada receta, en cada listado— y contestarla sumando el
 * kardex sería sumar la tabla más grande del sistema en cada lectura.
 *
 * Se puede **reconstruir** entera desde `stock_movements`, y habrá un comando que lo haga, igual que
 * `comandia:costing:rebuild-current`. Eso es lo que la hace segura: una proyección que no se puede
 * reconstruir es una segunda verdad.
 *
 * ## `lot_key`: unicidad cuando la llave incluye una columna NULL
 *
 * Mismo patrón que D93. En MySQL un índice único **no** deduplica `NULL`, así que
 * `unique(tenant, warehouse, article, lot_id)` permitiría **dos filas de saldo** para un artículo sin lotes
 * en el mismo almacén. Y dos filas de saldo no son un dato duplicado: son dos verdades, y la que se lea
 * depende del orden de la consulta.
 *
 * La columna generada convierte el `NULL` en 0 y la unicidad pasa a ser **estructural** en lugar de una
 * validación que una condición de carrera puede saltarse — y aquí las carreras son el caso normal, porque
 * dos movimientos simultáneos del mismo artículo es exactamente lo que hace un POS.
 *
 * ## Sin `total_value`, a propósito
 *
 * La tentación es guardar el valor del inventario aquí. No se hace: el valor depende del método de valuación
 * (D152: último costo, con el promedio como reporte), y guardarlo crearía una **tercera** fuente —además del
 * kardex y del costo vigente— que se desviaría en silencio. Se calcula al leer.
 *
 * ## `quantity` puede ser NEGATIVA
 *
 * Sin CHECK, y es la decisión de §6.2: el POS nunca se bloquea por inventario. Un saldo negativo es
 * información —«vendiste más de lo que el sistema creía que tenías»— y esconderlo con un cero sería perder
 * justo la señal que el conteo necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_stocks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE, al contrario que en el kardex: esto es una proyección, no evidencia. Si el almacén o el
            // artículo desaparecieran de verdad, su saldo derivado no tiene nada que conservar — la historia
            // sigue en `stock_movements`, que es RESTRICT y por tanto impediría el borrado de todos modos.
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            // RESTRICT, y no CASCADE como las otras tres. Dos razones, y la segunda es la que no se adivina:
            //
            //   1. Un lote con saldo no debería poder desaparecer. Los lotes no se borran —pasan a `depleted`
            //      o `expired`— así que RESTRICT describe lo que ya es cierto.
            //   2. **MySQL lo exige.** Una columna generada no puede basarse en una columna que tenga una
            //      acción `ON DELETE CASCADE` o `SET NULL`: el intento falla con «1215 Cannot add foreign key
            //      constraint». Y `lot_key` se genera precisamente desde `lot_id`.
            //
            // Por eso el patrón de D93 funcionó en `article_categories` sin tropezar con esto: allí la columna
            // base —`parent_id`— ya era RESTRICT.
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            // Firmada: puede ser negativa (§6.2).
            $table->decimal('quantity', 12, 4)->default(0);

            // El movimiento que dejó este saldo. Es el testigo que hace auditable la proyección: si
            // `quantity` no coincide con el `balance_after` de este movimiento, la proyección se desvió y
            // se puede detectar sin recorrer nada.
            $table->foreignId('last_movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->nullOnDelete();

            $table->timestamps();

            // Sin `ulid`: no es una entidad que se exponga por sí misma. El saldo se lee siempre a través
            // del artículo o del almacén, que sí tienen identificador público.

            // La consulta «¿dónde tengo este artículo?», que es la vista del dueño con varias sucursales.
            $table->index(['tenant_id', 'article_id'], 'article_stocks_tenant_article_index');

            // «¿Qué hay en este almacén?», con la cantidad en el índice para poder ordenar y filtrar por
            // saldo —lo que está en negativo, lo que está en cero— sin leer filas.
            $table->index(['tenant_id', 'warehouse_id', 'quantity'], 'article_stocks_tenant_warehouse_index');
        });

        // La columna generada y su índice único van en SQL directo por lo mismo que en `article_categories`:
        // el Blueprint expresa columnas generadas, pero no de forma que se pueda añadir un índice único
        // sobre ellas en la misma creación sin ambigüedad de orden.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_stocks`
            ADD COLUMN `lot_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (COALESCE(`lot_id`, 0)) STORED
        SQL);

        // LA garantía de la tabla: un solo saldo por (negocio, almacén, artículo, lote). Es también la fila
        // sobre la que el servicio de registro toma el lock, así que este índice es el que hace posible
        // serializar los movimientos concurrentes del mismo artículo.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_stocks`
            ADD UNIQUE `article_stocks_unique` (`tenant_id`, `warehouse_id`, `article_id`, `lot_key`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_stocks');
    }
};
