<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `theme_tokens` (CONFIG del tenant) — un color por fila: `acento`, `barra_lateral`, `fondo`…
 *
 * Relacional, sin JSON (regla de datos del proyecto): así el negocio edita un color con un UPDATE y se consulta como
 * cualquier otro dato. El front los inyecta como CSS custom properties (`--color-<token>`).
 *
 * `tenant_id` va aunque la fila sea alcanzable por la FK de `theme_id`: ADR-002 lo exige en TODA tabla de dominio. No
 * lleva índice propio por `tenant_id` porque ninguna consulta arranca por él —los tokens se cargan por `theme_id`, junto
 * con su tema— y ADR-002 pide índice justificado, no índice por reflejo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('theme_id')
                ->constrained('themes')
                ->cascadeOnDelete();

            $table->string('token', 60);
            $table->string('value', 40);

            $table->timestamps();

            // Un tema define cada token una sola vez. La FK de `theme_id` ya crea el índice con el que se cargan.
            $table->unique(['theme_id', 'token'], 'theme_tokens_theme_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_tokens');
    }
};
