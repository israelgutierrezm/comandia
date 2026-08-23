<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metas de reporte (Iteración 7, Tanda C, D46).
 *
 * Una meta es un objetivo para UNA medida de UN reporte, en un periodo, opcionalmente por sucursal (o consolidada, si
 * `branch_id` es null). El semáforo compara el valor real —que da el motor— contra esta meta. La dirección dice si más es
 * mejor (ventas) o menos es mejor (mermas).
 *
 * El valor va en DECIMAL(14,4) para cubrir tanto dinero como cantidades; la medida concreta decide su escala al presentar.
 * No es inmutable: una meta se ajusta (editar en el sitio), no se historiza —el objetivo del mes que viene reemplaza al de
 * éste sin dejar rastro contable, porque no es dinero ni existencias—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_goals', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('report_key', 80);
            $table->string('measure_key', 40);

            // Null = meta CONSOLIDADA (todas las sucursales del alcance); un id = meta de esa sucursal.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();

            $table->enum('period', ['day', 'week', 'month', 'year']);
            $table->decimal('target_value', 14, 4);
            $table->enum('direction', ['higher_better', 'lower_better'])->default('higher_better');

            $table->timestamps();

            $table->unique('ulid', 'report_goals_ulid_unique');

            // Una meta por (reporte, medida, sucursal, periodo). Con `branch_id` NULL MySQL no deduplica por sí solo; el
            // controlador usa updateOrCreate por ese alcance para no crear consolidadas duplicadas.
            $table->unique(['tenant_id', 'report_key', 'measure_key', 'branch_id', 'period'], 'report_goals_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_goals');
    }
};
