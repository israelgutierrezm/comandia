<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `preparation_areas` — áreas de preparación: cocina, barra, parrilla.
 *
 * Entidad de PRIMERA CLASE (ESPECIFICACIÓN_MAESTRA §3): son a la vez destino de
 * comandas y punto de consumo de inventario. Esa doble naturaleza es la razón por
 * la que no son una simple etiqueta del artículo.
 *
 * El ruteo a impresora NO está aquí: la impresora es del módulo Printing
 * (Iteración 4). El área dice a dónde va la comanda en términos de negocio; el
 * dispositivo físico cambia sin que cambie la organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_areas', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // NOT NULL, y no nullable con respaldo al almacén de la sucursal: el
            // descuento de inventario por receta corre en la cola `critical` y no
            // debe contener lógica de adivinanza. Si el área no dice de dónde
            // descuenta, el job tendría que suponerlo, y una suposición en el camino
            // del kardex es una existencia incorrecta.
            //
            // `restrictOnDelete`: no se borra un almacén del que un área consume.
            // Primero se reconfigura el área; si no, el descuento quedaría huérfano.
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->char('code', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 80);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'preparation_areas_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'code'], 'preparation_areas_tenant_branch_code_unique');

            // La consulta más caliente del módulo: el ruteo de comandas resuelve las
            // áreas activas de la sucursal en CADA envío a cocina.
            $table->index(['tenant_id', 'branch_id', 'status'], 'preparation_areas_tenant_branch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_areas');
    }
};
