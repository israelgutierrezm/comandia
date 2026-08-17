<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `units` — unidades de medida y sus conversiones (D22).
 *
 * ## La conversión es por factor a una base fija del sistema, no una tabla de pares
 *
 * Cada dimensión física tiene una unidad base constante en el código —gramo, mililitro, pieza— y
 * cada unidad declara cuántas unidades base equivale una suya: `kg` tiene `factor_to_base = 1000`.
 * Convertir entre dos unidades de la misma dimensión es entonces una división de factores.
 *
 * Lo importante no es que sea más simple: es que hace **imposible** una conversión inconsistente.
 * Con una tabla de pares (`from`, `to`, `factor`), alguien captura kg→lb = 2.2 y lb→kg = 0.45, las
 * dos filas se contradicen y nada lo detecta; el error aparece meses después como un costo que no
 * cuadra y nadie sabe por qué.
 *
 * ## No existe conversión entre dimensiones, y es deliberado
 *
 * Piezas a kilogramos no es una regla global: un limón no pesa lo que una sandía. Esa conversión
 * es **por artículo** y la resuelven las presentaciones de compra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // `ascii_bin` (D58): con la colación de la base, `Kg` y `kg` serían el mismo valor en
            // el índice único y el tenant no podría entender por qué su unidad "ya existe".
            $table->string('code', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 60);

            // Catálogo cerrado: un tenant no inventa dimensiones físicas.
            $table->enum('dimension', ['mass', 'volume', 'count']);

            // 8 decimales porque hay conversiones legítimamente pequeñas (1 gota ≈ 0.05 ml) y el
            // factor multiplica todas las cantidades del sistema: redondearlo propaga el error a
            // cada costo.
            $table->decimal('factor_to_base', 18, 8)->unsigned();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'units_ulid_unique');
            $table->unique(['tenant_id', 'code'], 'units_tenant_code_unique');

            // El selector de unidad de una línea de receta pide "unidades activas de la dimensión
            // del insumo" (invariante I3 del diseño). Es la única consulta de esta tabla que no es
            // por llave.
            $table->index(['tenant_id', 'dimension', 'status'], 'units_tenant_dimension_status_index');
        });

        // Un factor de 0 haría que toda cantidad expresada en esa unidad valiera cero, y un
        // factor negativo produciría cantidades negativas de insumo. Las dos cosas contaminarían
        // el costeo completo sin ningún error visible.
        DB::statement(<<<'SQL'
            ALTER TABLE `units`
            ADD CONSTRAINT `chk_units_factor_positive` CHECK (`factor_to_base` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
