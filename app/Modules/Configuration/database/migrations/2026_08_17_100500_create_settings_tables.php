<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración jerárquica: sistema (código) → tenant → sucursal
 * (ARQUITECTURA_MAESTRA §5).
 *
 * El default de sistema vive EN CÓDIGO, no en base. Estas tablas guardan sólo
 * overrides, y por eso `tenant_id` es siempre NOT NULL.
 *
 * DOS TABLAS y no una con `scope` y `branch_id` nullable (D78). La razón es
 * técnica y concreta: en MySQL un índice único trata cada NULL como distinto, así
 * que `(tenant_id, scope, branch_id, setting_key)` NO impediría dos ajustes de
 * tenant con la misma llave. Las salidas habituales —un `branch_id = 0` centinela
 * o una columna generada— rompen la FK real o meten magia en el esquema. Dos
 * tablas dan unicidad verdadera y FKs verdaderas, al precio de una migración casi
 * duplicada.
 *
 * El VALOR es una sola columna de texto tipada por el catálogo en código (D79): el
 * catálogo ya es la autoridad sobre el tipo y valida en la escritura. Columnas
 * `value_int`, `value_bool`, `value_decimal` darían tipado en base que nada
 * aprovecha, a cambio de que toda lectura decida de qué columna leer.
 *
 * Y NO se guarda JSON aquí: una llave que necesite estructura es una tabla que
 * falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // `ascii_bin`: es un identificador del catálogo (`pos.blind_precount`), y
            // `POS.Blind_Precount` no debe ser la misma llave.
            $table->string('setting_key', 80)->charset('ascii')->collation('ascii_bin');

            // Holgado a propósito: si algún día una llave necesitara más, el problema
            // sería la llave, no la columna.
            $table->string('setting_value', 500);

            $table->timestamps();

            // Único índice, y suficiente: se lee el conjunto completo del tenant una
            // vez por request y se cachea. Empieza por `tenant_id`, así que sirve para
            // esa lectura además de imponer la unicidad.
            $table->unique(['tenant_id', 'setting_key'], 'tenant_settings_tenant_key_unique');
        });

        Schema::create('branch_settings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            $table->string('setting_key', 80)->charset('ascii')->collation('ascii_bin');
            $table->string('setting_value', 500);

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'branch_id', 'setting_key'],
                'branch_settings_tenant_branch_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settings');
        Schema::dropIfExists('tenant_settings');
    }
};
