<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_area_routes` — qué área prepara qué.
 *
 * ## Una decisión que los documentos no cubrían (D240)
 *
 * El paso 8 es «comandar: ruteo por área», y ni la Especificación ni el diseño dicen **de dónde sale el área de un
 * artículo**. Sin eso, «ruteo por área» no tiene de dónde rutear.
 *
 * ## Por qué NO es una columna en `articles`
 *
 * Porque las áreas son **por sucursal**: `preparation_areas` es única por `(tenant, branch, code)` y cada una tiene su
 * propio almacén. Un artículo, en cambio, es del negocio entero. Una columna `articles.preparation_area_id` apuntaría a
 * la cocina de **una** sucursal, y en un negocio con dos sucursales las comandas de la segunda saldrían por la
 * impresora de la primera. No es una limitación teórica: la primera cadena de dos locales lo rompería el primer día.
 *
 * Tampoco una columna en `article_categories`, por lo mismo.
 *
 * ## Por qué la tabla vive en `Pos` y no en `Organization`
 *
 * Sería el sitio natural —las áreas son de `Organization`— y no puede ser: `Organization` es **kernel**, y el kernel no
 * depende de ningún módulo de dominio (§2, regla 1). Una FK a `article_categories` desde el kernel invertiría la flecha.
 * `Pos` ya depende de `Catalog` y de `Organization`, y el ruteo es una decisión del punto de venta: dónde se manda a
 * preparar lo que se vende.
 *
 * ## Cómo se resuelve, y por qué en ese orden
 *
 * 1. Regla del **artículo** en esa sucursal, si existe. Es el override: «las hamburguesas van a la parrilla, no a la
 *    cocina general».
 * 2. Regla de su **categoría**, y si no hay, la de la categoría padre. Es el caso normal y el que hace la carga de
 *    datos soportable: «Bebidas → barra» son dos toques, no cuatrocientos.
 * 3. **Nada**, y eso es legítimo: un item sin área no se comanda. Una cerveza que el mesero saca de la nevera no
 *    necesita que la cocina haga nada, y el diseño ya lo contempla al decir que un item sin área usa el almacén de la
 *    sucursal para el descuento de inventario.
 *
 * El ascenso es de un salto porque `article_categories` tiene exactamente dos niveles, garantizado por un CHECK de la
 * Iteración 2. Si algún día hubiera N niveles, esto es un `while`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_area_routes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT y no cascade: borrar una sucursal con reglas de ruteo vivas es un error de operación, no algo
            // que deba arrastrar silenciosamente su configuración.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('article_id')
                ->nullable()
                ->constrained('articles')
                ->restrictOnDelete();

            $table->foreignId('article_category_id')
                ->nullable()
                ->constrained('article_categories')
                ->restrictOnDelete();

            $table->foreignId('preparation_area_id')
                ->constrained('preparation_areas')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'pos_area_routes_ulid_unique');

            // Una regla por artículo y una por categoría en cada sucursal. En MySQL un índice único **no** deduplica
            // NULL, así que las filas de categoría —con `article_id` NULL— no compiten entre sí en el primer índice, ni
            // las de artículo en el segundo. Es el mismo comportamiento que en la Iteración 2 obligó a la columna
            // generada de `article_categories`; aquí juega a favor y no hace falta el truco.
            $table->unique(['tenant_id', 'branch_id', 'article_id'], 'pos_area_routes_article_unique');
            $table->unique(['tenant_id', 'branch_id', 'article_category_id'], 'pos_area_routes_category_unique');

            // Para resolver el ruteo al capturar: se entra por sucursal y se pregunta por artículo o categoría, que es
            // exactamente lo que cubren los dos únicos de arriba. No hace falta un índice más.
        });

        // Una regla es de un artículo **o** de una categoría, nunca de las dos ni de ninguna. Sin esto, una fila con
        // ambos NULL sería una regla que no rutea nada y una con ambos llenos tendría dos respuestas para la misma
        // pregunta — y el orden de resolución la volvería impredecible.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_area_routes`
            ADD CONSTRAINT `pos_area_routes_target_chk` CHECK (
                (`article_id` IS NOT NULL AND `article_category_id` IS NULL) OR
                (`article_id` IS NULL AND `article_category_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_area_routes');
    }
};
