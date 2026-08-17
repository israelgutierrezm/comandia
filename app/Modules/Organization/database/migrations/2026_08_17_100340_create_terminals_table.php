<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `terminals` — terminales de punto de venta.
 *
 * Aquí la terminal es sólo una entidad de la organización que el contexto puede
 * validar. Sin emparejamiento de dispositivo ni caja asociada: la sesión de caja
 * y el vínculo con el hardware son del POS (Iteración 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->char('code', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 80);
            $table->enum('status', ['active', 'inactive'])->default('active');

            // Diagnóstico de conectividad. El POS se detiene sin internet (riesgo
            // aceptado, §6.9) y saber cuándo se vio por última vez una terminal es lo
            // primero que se pregunta cuando una sucursal reporta un problema.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'terminals_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'code'], 'terminals_tenant_branch_code_unique');

            // Validar el header `X-Terminal` contra las terminales activas de la
            // sucursal, en cada petición del POS.
            $table->index(['tenant_id', 'branch_id', 'status'], 'terminals_tenant_branch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
