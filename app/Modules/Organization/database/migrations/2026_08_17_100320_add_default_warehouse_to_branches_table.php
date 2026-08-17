<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `branches.default_warehouse_id` — el almacén por defecto de la sucursal.
 *
 * Única migración "alter" del kernel, y existe por una circularidad genuina:
 * `warehouses.branch_id` apunta a `branches`, y `branches` apunta a su almacén
 * por defecto.
 *
 * Por qué la FK vive en `branches` y no un `is_default` en `warehouses`: MySQL no
 * tiene índices únicos parciales, así que "un solo almacén por defecto por
 * sucursal" no se podría imponer desde `warehouses` y quedaría en manos de la
 * aplicación. Con la FK aquí, la unicidad es estructural: una sucursal tiene una
 * columna, luego tiene a lo más un default.
 *
 * `nullOnDelete`: si se borra el almacén por defecto, la sucursal se queda sin
 * default —estado detectable y corregible— en lugar de arrastrar la sucursal
 * entera o dejar una referencia rota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->foreignId('default_warehouse_id')
                ->nullable()
                ->after('timezone')
                ->constrained('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_warehouse_id');
        });
    }
};
