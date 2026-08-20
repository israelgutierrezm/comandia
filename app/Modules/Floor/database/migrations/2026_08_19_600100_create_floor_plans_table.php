<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `floor_plans` y `floor_zones` — el salón (§6.4, D34).
 *
 * ## Qué entra en esta iteración y qué no
 *
 * Entra **saber en qué mesa está una cuenta**, que es lo que un restaurante necesita para operar. NO entra el editor
 * visual: dibujar el salón con el ratón es la superficie de la Iteración 6 y exige ADR-003 (SVG con Vue puro) más tiempo
 * real. Las mesas se dan de alta por formulario y la 6 las coloca sobre el plano.
 *
 * Las coordenadas nacen aquí de todos modos, y no es adelantarse: si la mesa no las tuviera, la Iteración 6 tendría que
 * migrar datos de un salón ya en uso para poder dibujarlo.
 *
 * ## Múltiples planos por sucursal desde el primer día
 *
 * D34 lo pide y la columna es la misma con uno o con cinco. Un negocio con terraza que cierra en temporada de lluvias
 * quiere dos planos, no mover mesas de zona; y añadir la tabla después obligaría a inventar un plano por omisión para
 * las mesas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_plans', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->string('name', 60);

            // El plano con el que abre la pantalla del salón. Se garantiza uno solo por sucursal con una columna
            // generada, el mismo patrón que la sesión abierta por terminal (D173): con dos «por omisión», la pantalla
            // elegiría uno al azar y el cambio no sería reproducible.
            $table->boolean('is_default')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'floor_plans_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'name'], 'floor_plans_tenant_branch_name_unique');

            $table->index(['tenant_id', 'branch_id', 'status'], 'floor_plans_tenant_branch_status_index');
        });

        // La columna generada va en un `ALTER` aparte porque MySQL no permite declararla junto a la creación cuando
        // depende de otra columna de la misma sentencia en algunas versiones. Es el mismo camino que usó `stock_counts`.
        DB::statement(
            'ALTER TABLE floor_plans ADD COLUMN default_branch_key BIGINT UNSIGNED '
            .'GENERATED ALWAYS AS (IF(is_default = 1, branch_id, NULL)) STORED'
        );

        Schema::table('floor_plans', function (Blueprint $table): void {
            $table->unique('default_branch_key', 'floor_plans_default_per_branch_unique');
        });

        Schema::create('floor_zones', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE aquí sí: una zona no existe fuera de su plano, y borrar el plano sin sus zonas dejaría zonas
            // huérfanas que ninguna pantalla podría mostrar.
            $table->foreignId('floor_plan_id')
                ->constrained('floor_plans')
                ->cascadeOnDelete();

            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'floor_zones_ulid_unique');
            $table->unique(['floor_plan_id', 'name'], 'floor_zones_plan_name_unique');

            $table->index(['tenant_id', 'floor_plan_id', 'sort_order'], 'floor_zones_plan_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_zones');
        Schema::dropIfExists('floor_plans');
    }
};
