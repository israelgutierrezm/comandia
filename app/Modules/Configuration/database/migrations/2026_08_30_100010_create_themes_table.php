<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `themes` (CONFIG del tenant) — el catálogo de temas visuales que el negocio pone a disposición de su gente.
 *
 * Cada negocio tiene su propio catálogo (se siembra al provisionar): así puede tener un tema institucional propio sin
 * afectar a nadie más, y editar un color es un UPDATE, no una migración. Los colores NO viven en JSON —lo prohíbe la
 * regla de datos del proyecto— sino en `theme_tokens`, una fila por token.
 *
 * `es_default` marca el tema por omisión del negocio; que sea único lo garantiza el servicio de siembra, no un índice,
 * porque son decenas de filas por tenant y un índice parcial en MySQL no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->string('key', 50);
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);

            // Si el tema admite que cada usuario ajuste algunos colores. El tema de alto contraste lo desactiva: dejar
            // tocar sus colores rompería justo la accesibilidad que es su razón de ser.
            $table->boolean('allows_user_override')->default(false);

            $table->timestamps();

            $table->unique('ulid', 'themes_ulid_unique');

            // La clave nombra el tema dentro del negocio y es como el sembrador lo encuentra para ser idempotente.
            $table->unique(['tenant_id', 'key'], 'themes_tenant_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
