<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `floor_elements` (ADR-011) — elementos DECORATIVOS del salón: muros/columnas, puertas y rótulos.
 *
 * Tabla propia y no una fila de `restaurant_tables` con un flag: un muro no tiene código, capacidad, estado ni cuenta, y
 * meterlo entre las mesas obligaría a filtrar en toda consulta de mesas —el día que una lo olvide, un muro aparece
 * sentable— (ADR-011, alternativa A rechazada). Comparte las coordenadas lógicas y el render de las mesas (ADR-003).
 *
 * Se BORRAN de verdad (sin `archived_at`): a diferencia de una mesa, ningún documento histórico apunta a un muro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_elements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('floor_plan_id')
                ->constrained('floor_plans')
                ->cascadeOnDelete();

            // Enum CERRADO (ADR-011): muro/columna, puerta, rótulo. Ampliarlo es una migración, no una columna libre.
            $table->enum('kind', ['wall', 'door', 'label']);

            // Sólo lo usa el rótulo (`label`); nulo en muros y puertas.
            $table->string('text', 120)->nullable();

            // Coordenadas LÓGICAS en centímetros (ADR-003), como las mesas.
            $table->decimal('x', 8, 2)->default(0);
            $table->decimal('y', 8, 2)->default(0);
            $table->decimal('width', 8, 2)->default(100);
            $table->decimal('height', 8, 2)->default(20);
            $table->decimal('rotation', 5, 2)->default(0);

            // Orden de apilado entre elementos (los elementos siempre van DETRÁS de las mesas).
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'floor_elements_ulid_unique');

            // El acceso es por plano (se cargan con su plano); la FK de `floor_plan_id` ya crea ese índice. `tenant_id`
            // va por ADR-002 aunque sea alcanzable por la FK, sin índice propio porque ninguna consulta arranca por él.
        });

        // Un elemento de cero o menos no se puede ni agarrar en la pantalla. Va en la base y no sólo en el Form Request
        // porque el sembrador y las pruebas escriben directo.
        DB::statement(<<<'SQL'
            ALTER TABLE `floor_elements`
            ADD CONSTRAINT `chk_floor_elements_dimensions_positive`
            CHECK (`width` > 0 AND `height` > 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `floor_elements` DROP CONSTRAINT `chk_floor_elements_dimensions_positive`');
        Schema::dropIfExists('floor_elements');
    }
};
