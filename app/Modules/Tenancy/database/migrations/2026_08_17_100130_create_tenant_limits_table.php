<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_limits` — límites medibles fijados por el super admin.
 *
 * Tabla propia y NO el sistema de configuración jerárquica, aunque técnicamente
 * cabría: estos valores los fija el super admin y el tenant no puede tocarlos ni
 * con permiso. Ponerlos en la misma tabla que los ajustes del tenant obligaría a
 * defender esa frontera con lógica en cada escritura; separarlos la hace
 * estructural.
 *
 * Y no columnas fijas, porque la forma comercial no está definida (D4) y cada
 * límite nuevo sería una migración.
 *
 * `limit_value` NULL significa SIN LÍMITE, no cero. El uso se MIDE, no se
 * almacena: `max_users` se compara contra un COUNT de membresías activas. Mismo
 * principio que los cortes calculados de ADR-004 y por la misma razón: un
 * contador se desincroniza y entonces hay dos verdades y ninguna forma de saber
 * cuál miente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_limits', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Catálogo cerrado en código: max_users, max_branches, max_warehouses,
            // max_terminals_per_branch. `ascii_bin` porque es un identificador.
            $table->string('limit_key', 60)->charset('ascii')->collation('ascii_bin');

            $table->unsignedInteger('limit_value')->nullable();

            $table->timestamps();

            // Único índice. Empieza por `tenant_id`, así que sirve también para leer
            // el conjunto completo del tenant, que es como se consume (y se cachea).
            $table->unique(['tenant_id', 'limit_key'], 'tenant_limits_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_limits');
    }
};
