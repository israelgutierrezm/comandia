<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_status_transitions` — INMUTABLE (append-only, D75).
 *
 * Por qué tabla propia y no la bitácora de auditoría: la bitácora tiene retención
 * de 12 meses en caliente más archivado (D47), y el historial de suspensiones y
 * bajas es evidencia comercial y legal que no puede depender de una política de
 * archivado. Una disputa de cobro puede llegar dos años después.
 *
 * Sin `updated_at`: una fila que se puede actualizar no es un historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_status_transitions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $estados = [
                'pending_activation',
                'active',
                'suspended',
                'read_only',
                'pending_deletion',
                'cancelled',
            ];

            // NULL en la fila de creación del tenant: no venía de ningún estado.
            $table->enum('from_status', $estados)->nullable();
            $table->enum('to_status', $estados);

            $table->string('reason', 255)->nullable();

            // NULL cuando la transición la hizo el sistema (impago detectado por un
            // job, purga programada) y no una persona.
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Única consulta que existe: "historia de este tenant en orden". La FK de
            // `tenant_id` ya crea su propio índice, pero éste lo incluye como prefijo
            // y además ordena, así que la lectura es un recorrido de índice.
            $table->index(['tenant_id', 'created_at'], 'tenant_status_transitions_tenant_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_status_transitions');
    }
};
