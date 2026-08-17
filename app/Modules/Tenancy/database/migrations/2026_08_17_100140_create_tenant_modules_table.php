<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_modules` — activación comercial de los módulos activables (D3).
 *
 * Sólo se materializan los módulos ACTIVABLES: `DigitalMenus` y `Ecommerce`. Los
 * del núcleo no tienen fila, porque preguntar si el POS está activo no tiene
 * sentido y una fila que siempre vale `true` es una invitación a apagarla por
 * error.
 *
 * Se guarda `is_enabled` con fechas en lugar de borrar la fila: "cuándo contrató
 * y cuándo canceló el e-commerce" es información comercial que hay que conservar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Valor del registro declarativo de config/comandia.php. `ascii_bin`
            // porque es un identificador y `DigitalMenus` no debe empatar con
            // `digitalmenus` por la colación de la base.
            $table->string('module', 40)->charset('ascii')->collation('ascii_bin');

            $table->boolean('is_enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            // Único índice: se lee el conjunto completo del tenant y se cachea junto
            // con la configuración. Empieza por `tenant_id`, así que cubre ambos usos.
            $table->unique(['tenant_id', 'module'], 'tenant_modules_tenant_module_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
