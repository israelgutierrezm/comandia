<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminal compartida operada por PIN (ADR-012).
 *
 * Dos cambios:
 *
 * 1. `terminals.is_shared` — marca la estación FIJA por la que rotan meseros. Las demás terminales
 *    siguen operándose con login de usuario; el modo compartido sólo aplica a las marcadas.
 *
 * 2. `terminal_devices` — un navegador/dispositivo enrolado a una terminal compartida. Es una
 *    **credencial de dispositivo** (sin usuario): sobre ella, cada mesero teclea su PIN para abrir
 *    una sesión de operación corta. El secreto se muestra una sola vez al enrolar y se guarda
 *    hasheado; se revoca por fila (aparato perdido) sin tocar la terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminals', function (Blueprint $table): void {
            // Apagado por omisión: una terminal es de login de usuario salvo que se marque compartida.
            $table->boolean('is_shared')->default(false)->after('status');
        });

        Schema::create('terminal_devices', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // El dispositivo pertenece a una terminal concreta; si la terminal se borra, el
            // dispositivo enrolado deja de tener sentido.
            $table->foreignId('terminal_id')
                ->constrained('terminals')
                ->cascadeOnDelete();

            $table->string('label', 80);

            // El secreto se canjea por una sesión de dispositivo al arrancar. Se guarda HASHEADO
            // (mismo criterio que el PIN y los tokens): en base nunca vive el secreto en claro.
            $table->string('secret_hash', 255)->charset('ascii')->collation('ascii_bin');

            // Diagnóstico de conectividad, como en `terminals`: cuándo canjeó por última vez.
            $table->timestamp('last_seen_at')->nullable();

            // Revocación por fila (aparato perdido/robado) sin dar de baja la terminal. Un
            // dispositivo revocado no puede canjear sesión.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'terminal_devices_ulid_unique');

            // Resolver los dispositivos vivos de una terminal (listado de administración, revocación).
            $table->index(['tenant_id', 'terminal_id'], 'terminal_devices_tenant_terminal_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_devices');

        Schema::table('terminals', function (Blueprint $table): void {
            $table->dropColumn('is_shared');
        });
    }
};
