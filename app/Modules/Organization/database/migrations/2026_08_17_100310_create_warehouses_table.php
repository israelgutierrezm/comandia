<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `warehouses` — almacenes, incluido el central sin sucursal (D11).
 *
 * Topología configurable: desde un almacén por sucursal hasta consumo fino por
 * área de preparación. El modelo soporta el caso rico y la configuración degrada
 * hacia lo simple.
 *
 * Un almacén NO cuenta como sucursal para el cobro (ESPECIFICACIÓN_MAESTRA §2):
 * `max_branches` cuenta `branches` y `max_warehouses` cuenta esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // NULL = almacén central: no pertenece a ninguna sucursal y surte a todas.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->cascadeOnDelete();

            // Redundante con `branch_id IS NULL` A PROPÓSITO: hace explícito en el
            // modelo lo que si no sería una convención tácita, y el CHECK de abajo
            // impide que las dos afirmaciones se contradigan. Un almacén central mal
            // marcado surtiría a todas las sucursales sin que nadie lo decidiera.
            $table->enum('kind', ['central', 'branch']);

            $table->char('code', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 120);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'warehouses_ulid_unique');
            $table->unique(['tenant_id', 'code'], 'warehouses_tenant_code_unique');

            // "Almacenes activos de esta sucursal": consulta de toda pantalla de
            // inventario y del alta de áreas de preparación. La FK de `branch_id`
            // crea su propio índice, pero éste empieza por `tenant_id` como manda
            // ADR-002 y añade el estado.
            $table->index(['tenant_id', 'branch_id', 'status'], 'warehouses_tenant_branch_status_index');
        });

        // MySQL 8 aplica los CHECK de verdad (no como MySQL 5.7, que los ignoraba).
        // Es la restricción que impide la contradicción entre `kind` y `branch_id`.
        DB::statement(<<<'SQL'
            ALTER TABLE `warehouses`
            ADD CONSTRAINT `chk_warehouses_kind_branch` CHECK (
                (`kind` = 'central' AND `branch_id` IS NULL) OR
                (`kind` = 'branch'  AND `branch_id` IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
