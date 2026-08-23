<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistas guardadas de reporte, POR USUARIO (Iteración 7, Tanda B, ADR-006, D45).
 *
 * Una vista guardada es un reporte con sus parámetros ya elegidos —agrupación y filtros— con un nombre. Es del autor
 * (membresía): nadie más la ve.
 *
 * ## Sin JSON: los parámetros van normalizados
 *
 * En vez de un blob JSON con los filtros, cada parámetro es una fila (`name`, `value`) en `saved_report_view_params`. La
 * agrupación se guarda como varias filas `name = 'group_by'` (una por dimensión); los filtros como `name = 'sold_from'`,
 * etc. Reconstruir la consulta es recorrer esas filas — la misma forma que envía la pantalla. Respeta la regla «sin JSON
 * en datos de dominio».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_report_views', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->string('report_key', 80);
            $table->string('name', 80);
            $table->timestamps();

            $table->unique('ulid', 'saved_report_views_ulid_unique');

            // Un nombre no se repite para el mismo usuario en el mismo reporte.
            $table->unique(['tenant_id', 'membership_id', 'report_key', 'name'], 'saved_report_views_unique_name');

            // «Mis vistas de este reporte»: la consulta de la pantalla.
            $table->index(['tenant_id', 'membership_id', 'report_key'], 'saved_report_views_owner_index');
        });

        Schema::create('saved_report_view_params', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('saved_report_view_id')->constrained('saved_report_views')->cascadeOnDelete();

            // El nombre del parámetro tal como viaja en la query: 'group_by', 'sold_from', 'branch'…
            $table->string('name', 40);
            $table->string('value', 120)->nullable();

            $table->index(['tenant_id', 'saved_report_view_id'], 'saved_report_view_params_view_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_report_view_params');
        Schema::dropIfExists('saved_report_views');
    }
};
