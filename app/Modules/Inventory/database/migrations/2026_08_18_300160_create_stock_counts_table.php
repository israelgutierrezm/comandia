<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_counts` — el conteo físico como documento (D24, §6.2).
 *
 * El flujo de §6.2 es **conteo → diferencia → ajuste masivo auditado**, y este documento existe para que el
 * ajuste masivo tenga un responsable y una explicación. Sin él, un inventario general aparecería en el kardex
 * como doscientos ajustes sueltos sin nada que los relacione, y la pregunta «¿de qué conteo salió esto?» no
 * tendría respuesta.
 *
 * ## Un solo conteo abierto por almacén, y es un índice único de verdad
 *
 * Dos conteos abiertos del mismo almacén son un error de doble aplicación esperando a ocurrir: los dos congelan
 * lo esperado en 40, el primero se cierra con 35 y aplica −5, y el segundo —que también dice 35— vuelve a
 * calcular su diferencia contra los 40 congelados y aplica −5 otra vez. El saldo acaba en 30 y nadie lo puede
 * explicar mirando ninguno de los dos conteos.
 *
 * Se impone con el patrón de D93: una columna generada que vale `warehouse_id` sólo mientras el conteo está en
 * captura, y `NULL` cuando ya se cerró o canceló. MySQL no deduplica `NULL`, así que un almacén puede tener mil
 * conteos cerrados y sólo uno abierto — la unicidad es estructural, no una validación que una carrera pueda
 * saltarse.
 *
 * **Lo que esto prohíbe, dicho claro:** dos personas no pueden contar en paralelo secciones distintas del mismo
 * almacén. Es una restricción real y se acepta a cambio de la garantía: los conteos por secciones se hacen en
 * serie (hoy las carnes, mañana los abarrotes), que es como se hace un conteo cíclico de todos modos. Si algún
 * día hace falta el paralelo, la evolución es cambiar la garantía por «un artículo no puede estar en dos conteos
 * abiertos», que es más preciso y ya no cabe en un índice.
 *
 * ## `warehouse_id` es RESTRICT
 *
 * Al contrario que en la proyección de saldos. Un conteo cerrado es evidencia: dice quién contó, cuándo, y qué
 * diferencias se aplicaron. Que desaparezca porque alguien borró el almacén dejaría en el kardex ajustes sin
 * documento que los explique. Y además MySQL lo exige, por lo mismo que en `article_stocks`: la columna generada
 * se basa en `warehouse_id` (D156).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->string('status', 20);

            // Quién lo inició y quién lo cerró, y son distintos a propósito (§6.2): quien cuenta no decide que
            // su conteo es la verdad. RESTRICT porque son la parte del documento que contesta «¿quién?»; una
            // membresía no se borra, se da de baja.
            $table->foreignId('started_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->foreignId('closed_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Quién AUTORIZÓ el cierre, cuando la diferencia valuada pasó el umbral. Es la misma columna que
            // las mermas (D172) y con el mismo propósito: distingue «lo cerró el gerente» de «el propietario
            // autorizó que el gerente lo cerrara».
            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();

            // Las DOS cifras del cierre, congeladas, y son distintas a propósito:
            //
            //   - `variance_value` es el NETO con signo: el impacto contable. Es la cifra del negocio, la que
            //     contesta «¿cuánto perdí en este conteo?», y la que va en el listado.
            //   - `variance_value_absolute` es el BRUTO: cuánto inventario se reescribió, sin compensar sobrantes
            //     con faltantes. Es la cifra del control, la que se comparó con el umbral de autorización.
            //
            // Un conteo con veinte mil de sobrante y veinte mil de faltante tiene neto cero y bruto cuarenta mil.
            // Guardar sólo el neto dejaría sin rastro auditable la decisión de pedir autorización, y guardar sólo
            // el bruto haría ilegible el listado. Las dos se congelan porque las dos se calcularon con los costos
            // del momento del conteo, y recalcularlas el mes que viene daría otras cifras.
            $table->decimal('variance_value', 12, 2)->nullable();
            $table->decimal('variance_value_absolute', 12, 2)->nullable();

            $table->string('notes', 300)->nullable();

            $table->timestamps();

            // El historial de conteos de un almacén, del más reciente al más viejo: es la pantalla de la
            // sección y la única consulta de listado que existe.
            $table->index(['tenant_id', 'warehouse_id', 'started_at'], 'stock_counts_tenant_warehouse_index');
        });

        // En SQL directo por lo mismo que en `article_stocks`: el Blueprint expresa columnas generadas, pero no
        // de forma que se pueda añadir el índice único sobre ellas en la misma creación.
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_counts`
            ADD COLUMN `open_warehouse_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(`status` = 'counting', `warehouse_id`, NULL)) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_counts`
            ADD UNIQUE `stock_counts_one_open_per_warehouse` (`tenant_id`, `open_warehouse_key`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
