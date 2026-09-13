<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camino de TOKEN para la terminal compartida (ADR-014, adenda a ADR-012).
 *
 * La fase (a)/(b) de ADR-012 resuelve la identidad del dispositivo y del operador en la SESIÓN (cookie),
 * que sirve al POS web. La app Flutter autentica por token, no por cookie, así que el kiosco móvil
 * necesita el gemelo por token: se canjea el mismo secreto de enrolamiento por un **token de
 * dispositivo** (como el agente de impresión), y la capa de operador —hoy en la sesión— se persiste en
 * la propia fila del dispositivo. Un operador a la vez por dispositivo, que es el modelo de la terminal
 * compartida. El flujo web por cookie no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terminal_devices', function (Blueprint $table): void {
            // Token de dispositivo (camino móvil). Se guarda HASHEADO (sha256, como el token del agente de
            // impresión): en base nunca vive el token en claro. Nulo hasta que el dispositivo canjea uno.
            $table->string('token_hash', 64)->charset('ascii')->collation('ascii_bin')->nullable()->after('secret_hash');

            // Capa de operador para el camino por token (el gemelo en BD de lo que la cookie guarda en sesión).
            // Un operador a la vez por dispositivo; se limpia al salir, al caducar por inactividad o al revocar.
            $table->foreignId('operator_membership_id')->nullable()->after('token_hash')
                ->constrained('tenant_memberships')->nullOnDelete();
            $table->timestamp('operator_last_activity_at')->nullable()->after('operator_membership_id');

            // Un token vive en un solo dispositivo; se resuelve por hash en cada petición del kiosco (índice
            // único, una sola comparación, como el agente de impresión). MySQL admite varios NULL bajo unique.
            $table->unique('token_hash', 'terminal_devices_token_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('terminal_devices', function (Blueprint $table): void {
            $table->dropUnique('terminal_devices_token_hash_unique');
            $table->dropConstrainedForeignId('operator_membership_id');
            $table->dropColumn(['token_hash', 'operator_last_activity_at']);
        });
    }
};
