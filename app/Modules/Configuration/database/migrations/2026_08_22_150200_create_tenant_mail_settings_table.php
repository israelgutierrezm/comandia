<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La configuración de correo (SMTP/Gmail) de un negocio (Iteración 7, Tanda D1).
 *
 * Una fila por tenant: con qué servidor SMTP envía sus correos (avisos, reportes programados). Sin ella, el sistema cae
 * al driver por omisión (`log` en desarrollo) y nada se rompe.
 *
 * ## La contraseña se guarda ENCRIPTADA
 *
 * `password` es un secreto: se cifra en reposo con la app key (cast `encrypted` en el modelo) y NUNCA vuelve al cliente
 * (el resource sólo dice «configurado» o «sin configurar»). Es una columna `text` porque el texto cifrado es más largo que
 * la contraseña. Para Gmail no es la contraseña de la cuenta sino una **Contraseña de aplicación** (lo explica la UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('host', 191);
            $table->unsignedSmallInteger('port');
            $table->enum('encryption', ['tls', 'ssl', 'none'])->default('tls');
            $table->string('username', 191);
            $table->text('password'); // cifrada (cast `encrypted`)
            $table->string('from_address', 191);
            $table->string('from_name', 120);

            // Cuándo se envió con éxito un correo de prueba: la señal de que la config sirve.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // Una configuración por negocio.
            $table->unique('tenant_id', 'tenant_mail_settings_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_settings');
    }
};
