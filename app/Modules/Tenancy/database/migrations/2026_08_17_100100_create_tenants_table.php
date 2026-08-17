<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants` — la raíz del aislamiento.
 *
 * EXCEPCIÓN DECLARADA a la Regla A de ADR-002: esta tabla no lleva `tenant_id`
 * porque su llave primaria ES el `tenant_id`. Es la primera de las cuatro
 * excepciones del proyecto (ITERACION_1_DISENO §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();

            // Identificador público. `ascii_bin` obligatorio: la colación de la base
            // es acento- y caso-insensible (D58) y sin esto `01hq…` y `01HQ…` serían
            // el mismo valor en el índice único.
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->string('name', 150);
            $table->string('legal_name', 255)->nullable();

            // Slug de superficies públicas: /m/{slug} y /t/{slug}. `ascii_bin` para
            // que `mi-fonda` y `Mi-Fonda` no colisionen por la colación de la base.
            $table->char('slug', 60)->charset('ascii')->collation('ascii_bin');

            // Seis estados (D70). `read_only` existe desde el día uno para no tener
            // que rehacer el middleware el día que se defina la política de impago.
            $table->enum('status', [
                'pending_activation',
                'active',
                'suspended',
                'read_only',
                'pending_deletion',
                'cancelled',
            ])->default('pending_activation');

            $table->string('contact_email', 150);
            $table->string('contact_phone', 20)->nullable();

            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'tenants_ulid_unique');

            // El slug resuelve la URL pública: dos tenants con el mismo slug harían
            // ambiguo el menú QR y la tienda.
            $table->unique('slug', 'tenants_slug_unique');

            // Único índice adicional justificable: el panel de super admin y el
            // futuro job de facturación filtran por estado. Sobre decenas de filas,
            // cualquier otro índice sería adorno con costo de escritura.
            $table->index('status', 'tenants_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
