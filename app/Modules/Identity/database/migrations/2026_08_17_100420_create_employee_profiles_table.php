<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `employee_profiles` — capa 3 de identidad: el perfil laboral por tenant.
 *
 * Base del futuro módulo de nómina y —por D66— **la fuente del nombre de toda
 * persona sin credenciales de acceso**. De ahí que los tres campos de nombre legal
 * sean NOT NULL: si esta tabla es el respaldo del nombre, no puede estar a medias.
 *
 * PII sin cifrar, con acceso restringido (D77): el RFC tiene que ser buscable para
 * CFDI y su unicidad por tenant verificable por índice, y cifrar exigiría *blind
 * indexes* —criptografía casera para un beneficio parcial—. La mitigación real va
 * donde importa: permiso dedicado `identity.employee_profiles.view_sensitive`,
 * lectura auditada, y cifrado del respaldo y del disco. Se revisa al construir
 * nómina, que es cuando el volumen de PII crece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('membership_id')
                ->constrained('tenant_memberships')
                ->cascadeOnDelete();

            // Nombre legal completo, para nómina y para CFDI. NOT NULL por D66.
            $table->string('legal_first_name', 60);
            $table->string('legal_paternal_surname', 60);
            $table->string('legal_maternal_surname', 60)->nullable();

            // Si es 1, `curp` puede quedar NULL: la CURP acepta "extranjero" (§4.1).
            $table->boolean('is_foreigner')->default(false);

            // `ascii_bin` OBLIGATORIO: con la colación de la base,
            // `GOMA850101HDFXXX01` y `gomá850101hdfxxx01` serían el mismo valor en el
            // índice único y la validación de unicidad quedaría comprometida. Se
            // normalizan a mayúsculas en el Form Request.
            $table->char('curp', 18)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('rfc', 13)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('nss', 11)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->date('birth_date')->nullable();
            $table->date('hired_at')->nullable();
            $table->date('terminated_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'employee_profiles_ulid_unique');

            // Relación 1:1 real, impuesta por el índice y no por convención.
            $table->unique('membership_id', 'employee_profiles_membership_unique');

            // Dos empleados del mismo tenant no pueden compartir CURP ni RFC.
            // Múltiples NULL permitidos para extranjeros y para quien no los capture.
            $table->unique(['tenant_id', 'curp'], 'employee_profiles_tenant_curp_unique');
            $table->unique(['tenant_id', 'rfc'], 'employee_profiles_tenant_rfc_unique');

            // Sin índices adicionales: se accede siempre por membresía, y la FK de
            // `membership_id` más el único de arriba ya lo cubren.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
