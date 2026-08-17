<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `article_current_costs` — el costo vigente como PROYECCIÓN (P4, aprobada).
 *
 * ## Por qué una proyección y no derivar siempre
 *
 * La verdad es la última fila de `article_costs`. Esta tabla es una caché con forma de tabla, y
 * existe porque costear una receta de 30 líneas exigiría 30 consultas de "última fila por artículo",
 * y costear un platillo con sub-recetas anidadas las multiplica por nivel.
 *
 * El patrón tiene precedente **explícito** en la propia especificación: "Kardex como fuente de
 * verdad; existencia como acumulado" (§6.2). Es la misma relación entre un historial append-only y
 * su acumulado consultable.
 *
 * Las tres condiciones que forman parte de la decisión aprobada:
 *
 *   1. La fila de `article_costs` y esta proyección se escriben en **la misma transacción**.
 *   2. Existe `php artisan comandia:costs:rebuild`, que la reconstruye desde el historial.
 *   3. Hay una prueba que compara proyección contra historial para todo el catálogo y falla si
 *      divergen.
 *
 * ## Por qué es una tabla propia y NO columnas en `articles` — desviación del diseño aprobado
 *
 * El diseño de la Iteración 2 (§2.4) puso `current_unit_cost` y `current_cost_id` **en `articles`**,
 * y P4 se aprobó con esa forma. Al implementarlo aparece que eso **contradice P1**, aprobada en el
 * mismo mensaje: `articles` es una tabla de `Catalog`, y una FK de `articles` a `article_costs` es
 * una referencia de `Catalog` a `Costing` — exactamente el sentido que P1 prohíbe ("Catalog no
 * conoce Costing").
 *
 * Las dos decisiones eran incompatibles y no lo advertí al escribir el diseño. La resolución
 * conserva la sustancia de las dos: sigue habiendo proyección —que es lo que P4 argumentaba, evitar
 * N consultas anidadas— y `Catalog` sigue sin conocer a `Costing`. Lo único que se pierde es que la
 * proyección viva en la misma fila, que era incidental al razonamiento: un JOIN a una tabla 1:1
 * resuelve lo mismo en una consulta.
 *
 * Gana además algo que no era el objetivo: `articles` se queda siendo sobre lo que se vende, y el
 * costo entero —historial y proyección— pertenece a un solo módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_current_costs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE y no RESTRICT, al contrario que en el historial: esto es caché. Si el
            // artículo desapareciera, su costo vigente no significa nada; la evidencia vive en
            // `article_costs`, que sí es RESTRICT.
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            $table->decimal('unit_cost', 12, 4)->unsigned();
            $table->timestamp('effective_at');

            // De qué fila del historial salió este valor. Sin esto, "de dónde viene este costo" se
            // contesta adivinando por fecha.
            $table->foreignId('source_cost_id')
                ->constrained('article_costs')
                ->restrictOnDelete();

            $table->timestamps();

            // Una sola fila por artículo: es lo que la hace una proyección y no otro historial.
            $table->unique(['tenant_id', 'article_id'], 'article_current_costs_tenant_article_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_current_costs');
    }
};
