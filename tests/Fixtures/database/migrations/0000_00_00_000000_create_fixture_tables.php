<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de apoyo para las pruebas del shared kernel.
 *
 * Existen SÓLO en el entorno de pruebas: la ruta de esta migración la registra
 * `AppServiceProvider` bajo `runningUnitTests()`.
 *
 * Por qué hacen falta: el mecanismo de aislamiento —global scope, relleno
 * automático de `tenant_id`, bloqueo del cambio de tenant, ULID público— hay que
 * probarlo contra tablas reales, con MySQL de verdad (D60), y hay que poder
 * probarlo ANTES de que exista el primer modelo del kernel. Probar el candado con
 * las tablas de negocio ataría las pruebas del kernel a la forma del dominio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 80);
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
        });

        Schema::create('unscoped_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->timestamps();
        });

        // Réplica fiel de cómo se declara un ULID público en el kernel: `ascii_bin`
        // sobre una base cuya colación es acento- y caso-insensible (D58). Sin esa
        // colación por columna, `01hq…` y `01HQ…` serían el mismo valor en el índice
        // único, y la unicidad del identificador público quedaría comprometida.
        Schema::create('ulid_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 80);
            $table->timestamps();

            $table->unique('ulid');
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ulid_fixtures');
        Schema::dropIfExists('unscoped_fixtures');
        Schema::dropIfExists('scoped_fixtures');
    }
};
