<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `expense_categories` — en qué se gasta (§6.5).
 *
 * ## El mismo catálogo para los dos tipos de gasto
 *
 * §6.5 lo dice expresamente: gastos desde caja y fuera de caja comparten catálogo de categorías. La diferencia entre
 * ellos es de dónde salió el dinero —y por tanto si afecta el arqueo—, no en qué se gastó. Dos catálogos separados harían
 * que «Gas» existiera dos veces y que el reporte de gastos por categoría tuviera que sumar dos listas que se desvían.
 *
 * ## Se siembran unas cuantas, y se dan de baja, no se borran
 *
 * A diferencia de los motivos de merma —que nacen vacíos a propósito porque son las categorías de pérdida del negocio
 * (D27, D225)— aquí sí se siembran: los gastos de una cocina son los mismos en todas partes (gas, luz, agua, renta,
 * sueldos, mantenimiento) y una lista vacía sólo conseguiría que el primer gasto urgente se registrara en una categoría
 * inventada al vuelo.
 *
 * Las sembradas llevan `is_system` para que no se borren: los gastos ya registrados las citan, y un gasto que no puede
 * decir en qué se gastó no sirve para el reporte que justifica su existencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('name', 60);

            // Las sembradas por el sistema: se desactivan, no se borran.
            $table->boolean('is_system')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'expense_categories_ulid_unique');

            // El nombre es único dentro del negocio: dos categorías «Gas» harían que el reporte por categoría partiera
            // el mismo gasto en dos renglones, que es justo lo que un catálogo existe para evitar.
            $table->unique(['tenant_id', 'name'], 'expense_categories_tenant_name_unique');

            $table->index(['tenant_id', 'status', 'sort_order'], 'expense_categories_tenant_status_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
