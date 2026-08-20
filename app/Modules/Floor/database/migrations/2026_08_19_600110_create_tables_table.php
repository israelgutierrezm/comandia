<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `restaurant_tables` — las mesas (§6.4).
 *
 * ## Por qué NO se llama `tables`
 *
 * El diseño la llamaba `tables`, y es un nombre que hay que evitar: `SHOW TABLES`, `information_schema.tables` y media
 * documentación de MySQL usan esa palabra, así que cualquier consulta de esquema escrita a mano acaba ambigua y
 * cualquier error de sintaxis apunta al sitio equivocado. La convención del proyecto es plural inglés sin prefijos, con
 * excepciones documentadas (CLAUDE.md) — **ésta es una**, y se documenta aquí.
 *
 * ## Las coordenadas son LÓGICAS, nunca píxeles (ADR-003)
 *
 * Un salón dibujado en píxeles se rompe en la primera pantalla con otra resolución. Se guardan como `DECIMAL(8,2)` en
 * unidades del plano, y quien dibuje decide la escala. Nacen con un valor por omisión porque en esta iteración las mesas
 * se dan de alta por formulario: la Iteración 6 las coloca.
 *
 * ## `reserved` está en el enum y no se usa
 *
 * D33 dejó las reservaciones fuera de v1 y pidió el enum preparado. Está, y añadirlo después habría sido un `ALTER` de
 * una tabla en uso — un `ALTER TABLE ... MODIFY COLUMN` sobre un enum bloquea la tabla, y el salón es lo que más se
 * consulta durante el servicio.
 *
 * ## La unión de mesas no tiene tabla, y es deliberado
 *
 * `joined_to_table_id` apunta a la mesa principal. Una unión es **un estado del momento**, no un documento: se hace
 * porque llegaron ocho personas y se deshace al pagar (D32, §6.4). Lo que sí queda registrado es la cuenta que la usó,
 * que es lo que alguien querría auditar — una tabla de uniones guardaría el historial de mover sillas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // La sucursal va DENOTADA aunque la zona ya la implique por su plano: el índice de la consulta del salón
            // —«las mesas de esta sucursal y su estado»— empieza por `tenant_id` y necesita la sucursal a mano. Sin
            // ella habría que unir dos tablas en la consulta que más veces corre durante el servicio.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('floor_zone_id')
                ->constrained('floor_zones')
                ->restrictOnDelete();

            $table->char('code', 10)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 60)->nullable();

            $table->unsignedSmallInteger('seats')->default(4);

            $table->enum('status', ['free', 'occupied', 'bill_requested', 'needs_cleaning', 'reserved'])
                ->default('free');

            // Coordenadas lógicas (ADR-003). Con valores por omisión porque el alta es por formulario en esta
            // iteración; la 6 las coloca sobre el plano.
            $table->decimal('x', 8, 2)->default(0);
            $table->decimal('y', 8, 2)->default(0);
            $table->decimal('width', 8, 2)->default(80);
            $table->decimal('height', 8, 2)->default(80);
            $table->decimal('rotation', 5, 2)->default(0);

            $table->enum('shape', ['rectangle', 'circle'])->default('rectangle');

            // La unión temporal. RESTRICT: borrar la mesa principal de una unión activa dejaría mesas apuntando a la
            // nada, y el salón mostraría una unión que no existe.
            $table->foreignId('joined_to_table_id')
                ->nullable()
                ->constrained('restaurant_tables')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'restaurant_tables_ulid_unique');

            // El código es único en la SUCURSAL y no en la zona: «M1» tiene que ser una sola mesa para quien la
            // nombra en voz alta, y dos zonas con su M1 producirían la peor confusión posible en un servicio.
            $table->unique(['tenant_id', 'branch_id', 'code'], 'restaurant_tables_tenant_branch_code_unique');

            // LA consulta del salón: las mesas de una sucursal con su estado. Corre en cada refresco de la pantalla de
            // piso, que durante el servicio es constantemente.
            $table->index(['tenant_id', 'branch_id', 'status'], 'restaurant_tables_tenant_branch_status_index');

            // «¿Qué mesas están unidas a ésta?», que es lo que hay que resolver al pagar para deshacer la unión.
            $table->index(['tenant_id', 'joined_to_table_id'], 'restaurant_tables_joined_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
