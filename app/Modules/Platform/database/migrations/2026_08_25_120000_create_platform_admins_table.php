<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El super administrador de la PLATAFORMA vive en su propia tabla, con su propio guard aislado (como el «central» de
 * otros SaaS). No es un usuario de ningún negocio: por eso NO lleva `tenant_id` —es una identidad de plataforma, no un
 * modelo de dominio (excepción declarada al ADR-002, como `users`)—.
 *
 * Al mismo tiempo se retira `users.is_super_admin`: con la plataforma en su propia tabla, ese flag ya no gobierna nada
 * y una columna que no manda nada sólo confunde a quien la ve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('ulid')->unique();
            $table->string('name', 120);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false);
        });

        Schema::dropIfExists('platform_admins');
    }
};
