<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modificadores: grupos, opciones y su asignación a artículos (D7, §6.1).
 *
 * ## Los grupos son del tenant y se REUTILIZAN
 *
 * "Término de la carne" lo comparten ocho cortes. Si cada artículo tuviera su copia, editar la regla exigiría
 * editarla ocho veces — y garantizaría que se editen siete.
 *
 * ## Las reglas viven en el GRUPO y no se sobrescriben por artículo (P8)
 *
 * Un artículo que necesita reglas distintas usa un grupo distinto. La alternativa —permitir override por
 * artículo— metería una cascada en la validación más caliente del POS ("¿puedo comandar esto?"), y ahí una
 * regla ambigua no es un bug de interfaz: es un platillo mal preparado y un cliente esperando.
 *
 * ## `allows_quantity` es D7 literal
 *
 * "Selección múltiple con cantidades (ej. 3 shots)". Sin esa bandera, pedir tres shots exigiría tres
 * modificadores idénticos en la comanda, y la cocina tendría que contarlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name', 80);

            // ---- Reglas de selección (D7) ----
            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('min_selections')->default(0);

            // NULL = sin límite. Es distinto de un número alto: "elige los que quieras" y "elige hasta 200"
            // se cuentan igual en la práctica, pero sólo el primero se puede explicar en una pantalla.
            $table->unsignedTinyInteger('max_selections')->nullable();

            $table->boolean('allows_quantity')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'modifier_groups_ulid_unique');
            $table->unique(['tenant_id', 'name'], 'modifier_groups_tenant_name_unique');

            // "Los grupos activos del negocio": el selector al armar un artículo. Es la única consulta de la
            // tabla que no es por llave.
            $table->index(['tenant_id', 'status'], 'modifier_groups_tenant_status_index');
        });

        // Las dos contradicciones posibles entre las reglas, cerradas en la base y no sólo en validación: un
        // grupo obligatorio con mínimo 0 no obliga a nada, y un máximo menor que el mínimo hace imposible
        // cualquier selección válida. Las dos dejarían al POS sin poder comandar y sin decir por qué.
        DB::statement(<<<'SQL'
            ALTER TABLE `modifier_groups`
            ADD CONSTRAINT `chk_modifier_groups_selections` CHECK (
                (`max_selections` IS NULL OR `max_selections` >= `min_selections`)
                AND (`is_required` = FALSE OR `min_selections` >= 1)
            )
        SQL);

        Schema::create('modifiers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('modifier_group_id')
                ->constrained('modifier_groups')
                ->cascadeOnDelete();

            $table->string('name', 80);

            // CON IVA incluido, como todo precio del sistema (D30). Sin negativos (P14): un modificador que
            // resta es un descuento, y los descuentos tienen permiso, motivo y actor propios (§6.3).
            // Permitirlos aquí sería una puerta para descontar sin dejar rastro.
            $table->decimal('extra_price', 12, 2)->default(0.00);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'modifiers_ulid_unique');

            // Dos opciones con el mismo nombre en el mismo grupo son dos que alguien va a confundir al
            // comandar.
            $table->unique(['modifier_group_id', 'name'], 'modifiers_group_name_unique');

            // "Las opciones activas de este grupo, en orden": lo que pinta el POS al abrir el modificador.
            $table->index(
                ['tenant_id', 'modifier_group_id', 'status', 'sort_order'],
                'modifiers_tenant_group_status_order_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `modifiers`
            ADD CONSTRAINT `chk_modifiers_extra_price_not_negative` CHECK (`extra_price` >= 0)
        SQL);

        Schema::create('article_modifier_group', function (Blueprint $table): void {
            // Regla A sin excepciones de conveniencia: `tenant_id` va aunque sea alcanzable por FK.
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            $table->foreignId('modifier_group_id')
                ->constrained('modifier_groups')
                ->cascadeOnDelete();

            // El orden en que se le presentan los grupos a quien captura la orden. Vive en el pivote y no en
            // el grupo porque el mismo grupo puede ir primero en un artículo y tercero en otro.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['article_id', 'modifier_group_id']);

            // "¿Qué artículos usan este grupo?" — la consulta que hay que hacer ANTES de editar sus reglas,
            // porque el cambio los afecta a todos.
            $table->index(['tenant_id', 'modifier_group_id'], 'article_modifier_group_tenant_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_modifier_group');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};
