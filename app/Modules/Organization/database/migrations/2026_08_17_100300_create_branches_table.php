<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `branches` — sucursales.
 *
 * `default_warehouse_id` NO se declara aquí: la dependencia es circular
 * (`warehouses` necesita `branches` y `branches` apunta al almacén por defecto).
 * Se agrega en la migración 100320, que es la única "alter" del kernel y existe
 * por esa circularidad genuina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // El código entra en el folio de los documentos y es la serie de
            // foliación por defecto (§7). `ascii_bin` porque es un identificador.
            $table->char('code', 10)->charset('ascii')->collation('ascii_bin');

            $table->string('name', 120);
            $table->enum('status', ['active', 'inactive'])->default('active');

            // Identificador IANA. COLUMNA y no llave de configuración, aunque D20 diga
            // que los toggles van al sistema jerárquico: no es un toggle, es un dato
            // estructural que necesitan las consultas que calculan "el día" de un
            // corte. Resolverlo por cascada de configuración en cada reporte sería
            // absurdo.
            $table->string('timezone', 64)->charset('ascii')->collation('ascii_bin')->default('America/Mexico_City');

            // Dirección en columnas y no en una tabla polimórfica: una sucursal tiene
            // exactamente una dirección, siempre. Los clientes tienen 0..N (D42) y ahí
            // sí habrá tabla propia, en la Iteración 7. Una tabla compartida obligaría
            // a un join para el caso más simple que existe.
            $table->string('street', 160)->nullable();
            $table->string('exterior_number', 20)->nullable();
            $table->string('interior_number', 20)->nullable();
            $table->string('neighborhood', 120)->nullable();
            $table->string('municipality', 120)->nullable();
            $table->string('state', 80)->nullable();
            $table->char('postal_code', 5)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('country', 2)->charset('ascii')->collation('ascii_bin')->default('MX');

            $table->string('phone', 20)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'branches_ulid_unique');

            // El código entra en el folio: dos sucursales con el mismo código
            // producirían folios ambiguos.
            $table->unique(['tenant_id', 'code'], 'branches_tenant_code_unique');

            // El NOMBRE no es único a propósito: dos sucursales pueden llamarse
            // "Centro" en ciudades distintas, y prohibirlo sería una regla inventada.

            // Todo selector de sucursal y todo reporte consolidado filtra las
            // sucursales activas del tenant.
            $table->index(['tenant_id', 'status'], 'branches_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
