<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `membership_theme_overrides` (CONFIG del tenant) — los ajustes personales de color de una persona sobre su tema.
 *
 * Un token por fila (relacional, sin JSON). Por MEMBRESÍA y no por usuario: la personalización es por negocio, igual que
 * la elección de tema. Sólo se aplican si el tema elegido lo permite (`themes.allows_user_override`); el servicio lo
 * comprueba antes de mezclarlos en la cascada.
 *
 * `tenant_id` va por ADR-002 aunque sea alcanzable por la FK de `membership_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_theme_overrides', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('membership_id')
                ->constrained('tenant_memberships')
                ->cascadeOnDelete();

            $table->string('token', 60);
            $table->string('value', 40);

            $table->timestamps();

            // Una persona sobrescribe cada token una sola vez. La FK de `membership_id` crea el índice de acceso.
            $table->unique(['membership_id', 'token'], 'membership_theme_overrides_membership_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_theme_overrides');
    }
};
