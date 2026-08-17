<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de infraestructura del framework: sesiones y restablecimiento de
 * contraseña.
 *
 * Era la migración base `create_users_table` de Laravel. La tabla `users` se
 * movió a `app/Modules/Identity/database/migrations` porque la identidad es del
 * kernel y su forma la define el diseño de la Iteración 1 (nombre por partes,
 * ULID público, 2FA, super admin). Estas dos tablas se quedan aquí porque no son
 * dominio: son el respaldo del driver de sesión y del flujo de recuperación de
 * contraseña de Laravel.
 *
 * `sessions.user_id` deliberadamente SIN llave foránea, igual que en el esqueleto
 * de Laravel: el driver de sesión escribe antes de que exista el usuario en la
 * petición y una FK aquí rompería el guardado de sesiones anónimas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 150)->primary();
            $table->string('token', 255)->charset('ascii')->collation('ascii_bin');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id', 255)->charset('ascii')->collation('ascii_bin')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
