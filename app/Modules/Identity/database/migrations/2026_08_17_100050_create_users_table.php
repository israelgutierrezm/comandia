<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users` — capa 1 de identidad: el usuario global del SaaS.
 *
 * EXCEPCIÓN DECLARADA a la Regla A de ADR-002: no lleva `tenant_id` porque el
 * correo es único en toda la plataforma y una persona puede pertenecer a N
 * tenants independientes (ESPECIFICACIÓN_MAESTRA §4.1). El aislamiento vive en
 * `tenant_memberships`, no aquí.
 *
 * Se crea la primera de todas las migraciones del kernel: es la única tabla sin
 * dependencias, y `tenant_status_transitions` ya necesita referenciarla.
 *
 * Reemplaza a la migración base de Laravel, que quedó reducida a `sessions` y
 * `password_reset_tokens`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            // Nombre por partes en TRES campos (D76). El mercado es México
            // exclusivamente y el apellido materno es un campo real en CURP, RFC y
            // nómina: guardarlo dentro de "apellidos" obligaría a partirlo después
            // con heurísticas, justo cuando se necesite para timbrar.
            $table->string('first_name', 60);
            $table->string('paternal_surname', 60);
            $table->string('maternal_surname', 60)->nullable();

            // Conserva la colación por defecto de la base (acento- y
            // caso-insensible) A PROPÓSITO: para un correo, que `Ana@x.com` y
            // `ana@x.com` sean el mismo valor es el comportamiento correcto.
            $table->string('email', 150);
            $table->timestamp('email_verified_at')->nullable();

            $table->string('password', 255)->charset('ascii')->collation('ascii_bin');
            $table->string('remember_token', 100)->charset('ascii')->collation('ascii_bin')->nullable();

            // 2FA TOTP opcional (D54, §10.2). Cifrados en reposo por cast del modelo;
            // TEXT porque el texto cifrado es más largo que el secreto.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // El super admin queda FUERA de Spatie (D68) para que `roles.tenant_id`
            // pueda ser NOT NULL y la Regla A no tenga excepciones.
            $table->boolean('is_super_admin')->default(false);

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'users_ulid_unique');

            // Correo único en todo el SaaS (§4.1). Es además el índice del login.
            $table->unique('email', 'users_email_unique');

            // Sin índice sobre `is_super_admin`: son un puñado de filas y el filtro
            // nunca es selectivo en el sentido útil.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
