<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_categories` — categorías de dos niveles (D18).
 *
 * ## `level` es redundante con `parent_id`, a propósito
 *
 * Mismo patrón que `warehouses.kind` de la Iteración 1: la columna hace explícito en el modelo lo
 * que si no sería convención tácita, y el CHECK impide que las dos afirmaciones se contradigan.
 *
 * El CHECK no puede impedir que una categoría de nivel 2 apunte a otra de nivel 2 —un CHECK no
 * consulta otras filas—. Eso lo impone el servicio de aplicación y lo verifica una prueba. D18 dice
 * dos niveles y esto los da; un tercer nivel exige una decisión, no una migración.
 *
 * ## `parent_key`: unicidad cuando la llave incluye una columna NULL (P2)
 *
 * En MySQL un índice único **no** deduplica NULL, así que `unique(tenant, parent_id, name)`
 * permitiría dos categorías raíz llamadas "Bebidas". La columna generada convierte el NULL en 0 y
 * la unicidad pasa a ser **estructural** en lugar de una validación que una condición de carrera
 * puede saltarse.
 *
 * Es `STORED` y no `VIRTUAL` porque MySQL sólo admite índices únicos sobre columnas generadas
 * almacenadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_categories', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT y no CASCADE: borrar una categoría padre no puede llevarse las
            // subcategorías —y con ellas la clasificación de los artículos— por un clic.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('article_categories')
                ->restrictOnDelete();

            $table->unsignedTinyInteger('level');
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'article_categories_ulid_unique');

            // La única consulta real de la tabla: "el árbol completo de este negocio, en orden".
            // Un solo recorrido del índice resuelve el listado entero.
            $table->index(['tenant_id', 'parent_id', 'sort_order'], 'article_categories_tree_index');
        });

        // La columna generada y su índice único van en SQL directo: el Blueprint de Laravel
        // expresa columnas generadas, pero no de forma que se pueda añadir un índice único sobre
        // ellas en la misma creación sin ambigüedad de orden.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_categories`
            ADD COLUMN `parent_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (COALESCE(`parent_id`, 0)) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `article_categories`
            ADD UNIQUE `article_categories_tenant_parent_name_unique` (`tenant_id`, `parent_key`, `name`)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `article_categories`
            ADD CONSTRAINT `chk_article_categories_depth` CHECK (
                (`level` = 1 AND `parent_id` IS NULL) OR
                (`level` = 2 AND `parent_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_categories');
    }
};
