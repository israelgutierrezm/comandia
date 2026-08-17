<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `recipes` — la cabecera de una receta (D16).
 *
 * ## Por qué existe una cabecera y no sólo líneas
 *
 * Por `output_quantity`, que es lo que hace posible el costeo en cascada. Una receta de salsa cuesta
 * $100 y **rinde 2 L**; el costo por litro es $50, y es ese número el que entra en la receta de las
 * enchiladas. Sin cabecera no habría dónde poner el rendimiento y el costo de una sub-receta quedaría
 * indefinido.
 *
 * ## Falta `modifier_id`, y es deuda declarada
 *
 * El diseño (§3.1) define el dueño como artículo **XOR modificador**: un modificador con impacto en
 * receta —"extra queso" consume 30 g de queso— tiene su propia receta. Pero `modifiers` es el paso 10
 * de la iteración y **no existe todavía**, así que no se puede declarar su FK.
 *
 * Se construye ahora con `article_id NOT NULL` y en el paso 10 se hace nullable, se agrega
 * `modifier_id` y el `CHECK` de exclusividad. La migración es aditiva salvo el cambio de nulabilidad,
 * y el índice único `(tenant_id, article_id)` sigue funcionando cuando la columna admita NULL —MySQL no
 * deduplica NULL, que es exactamente lo que hará falta para que las recetas de modificador no
 * colisionen entre sí—.
 *
 * La alternativa era crear las tablas de modificadores ahora, sin código que las use. Se descartó por
 * la misma razón por la que se difirieron las ventanas de horario: una tabla sin consumidor aparenta
 * una capacidad que no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: la receta no tiene sentido sin su artículo, y el artículo se ARCHIVA en lugar
            // de borrarse (D80), así que en la práctica esto no se dispara. Si algún día se borra un
            // artículo de verdad —una limpieza de datos de prueba—, su receta debe irse con él.
            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            // Cuánto rinde la receta y en qué unidad. La unidad debe compartir dimensión con la unidad
            // base del artículo: si no, el costo por unidad base sería incalculable. Lo impone el
            // servicio de aplicación, porque un CHECK no puede consultar `units` ni `articles`.
            $table->decimal('output_quantity', 12, 4)->unsigned()->default(1);

            $table->foreignId('output_unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            $table->string('notes', 500)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'recipes_ulid_unique');

            // Invariante I1: a lo más UNA receta por artículo. Dos recetas para el mismo platillo
            // serían dos costos distintos para la misma cosa, y el motor de costeo tendría que elegir
            // sin criterio.
            $table->unique(['tenant_id', 'article_id'], 'recipes_tenant_article_unique');
        });

        // Un rendimiento de 0 sería una división por cero al calcular el costo por unidad: costo
        // infinito, propagado a todo lo que use esta sub-receta.
        DB::statement(<<<'SQL'
            ALTER TABLE `recipes`
            ADD CONSTRAINT `chk_recipes_output_positive` CHECK (`output_quantity` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
