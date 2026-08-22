<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de una exportación de reporte (Iteración 7, Tanda B, ADR-006 regla 5).
 *
 * Un export pesado NO corre en la petición del usuario: se encola en `exports`, y esta fila lleva su estado
 * (pending → ready | failed) para que la pantalla lo consulte y descargue cuando esté listo. Los PARÁMETROS del reporte
 * (agrupación, filtros) NO se guardan aquí —viajan en el payload del job, que Laravel serializa—: la tabla sólo registra
 * el RESULTADO, así que no hace falta JSON en dominio.
 *
 * `file_path` es relativo al disco privado de exports (fuera del webroot); la descarga va por un endpoint autenticado y
 * con alcance de tenant, nunca por URL directa. La fila es mutable a propósito (el estado avanza), por eso no es inmutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Quién lo pidió: un export es de su autor. La descarga se restringe a él.
            $table->foreignId('membership_id')->constrained('tenant_memberships')->cascadeOnDelete();

            $table->string('report_key', 80);
            $table->enum('format', ['pdf', 'xlsx', 'csv']);
            $table->enum('status', ['pending', 'ready', 'failed'])->default('pending');

            // El nombre legible del reporte, congelado: el catálogo podría cambiar y la descarga debe seguir teniendo sentido.
            $table->string('label', 120);

            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 300)->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('ulid', 'report_exports_ulid_unique');

            // «Mis exportaciones, las más recientes primero»: la consulta de la pantalla de descargas.
            $table->index(['tenant_id', 'membership_id', 'created_at'], 'report_exports_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
