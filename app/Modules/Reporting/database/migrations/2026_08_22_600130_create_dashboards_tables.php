<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tableros y sus widgets (Iteración 7, Tanda C, D46).
 *
 * Un tablero es del autor; si se PUBLICA a un rol, lo ven quienes tienen ese rol activo. Un widget es un reporte + una
 * visualización (número, semáforo, barras, top-N) + qué medida/dimensión mostrar + su posición en el grid. Los datos los
 * trae el motor al pintar cada widget, con el scope del que mira (D46): el mismo tablero muestra a cada gerente lo de sus
 * sucursales. El permiso del widget se HEREDA del reporte (ADR-006): no se pinta si el rol no lo tiene.
 *
 * Todo en columnas tipadas —sin JSON en dominio—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('name', 80);

            // Null = personal (sólo el autor). Un rol = publicado a quienes tengan ese rol activo.
            $table->foreignId('published_role_id')->nullable()->constrained('roles')->nullOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'dashboards_ulid_unique');
            $table->index(['tenant_id', 'membership_id'], 'dashboards_owner_index');
            $table->index(['tenant_id', 'published_role_id'], 'dashboards_published_index');
        });

        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();

            $table->string('report_key', 80);
            $table->enum('visualization', ['numero', 'semaforo', 'barras', 'topn']);
            $table->string('title', 80);

            // Qué mostrar, según el tipo: la medida (número/semáforo/barras/top-N) y la dimensión (barras/top-N).
            $table->string('measure_key', 40)->nullable();
            $table->string('dimension_key', 40)->nullable();

            // Sólo el semáforo: el periodo de la meta contra la que compara.
            $table->enum('period', ['day', 'week', 'month', 'year'])->nullable();

            // Sólo el top-N: cuántos.
            $table->unsignedSmallInteger('top_n')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique('ulid', 'dashboard_widgets_ulid_unique');
            $table->index(['tenant_id', 'dashboard_id', 'position'], 'dashboard_widgets_grid_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
