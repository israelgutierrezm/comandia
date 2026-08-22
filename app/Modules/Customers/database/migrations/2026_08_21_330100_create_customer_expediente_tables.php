<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El EXPEDIENTE del cliente que la Iteración 4 difirió (§6.6, D42, D43, ADR-005).
 *
 * La Iteración 4 construyó el cliente mínimo que el cobro necesita. Esto lo EXTIENDE: perfiles fiscales, direcciones y
 * cumpleaños. No recrea `customers`; añade sobre él.
 *
 * ## Perfiles fiscales (0..N), CFDI-ready SIN timbrar
 *
 * Captura y valida los datos que un CFDI necesita —RFC, razón social, CP fiscal, régimen y uso CFDI— contra los
 * catálogos oficiales (`SatCatalog`), pero NO timbra (ADR-005). El RFC se valida por FORMA, no por validez fiscal
 * (D200/D266, el mismo criterio que proveedores). Los códigos de régimen y uso se guardan como cadena, validados contra
 * el catálogo cerrado en código —no hay FK a una tabla de catálogo porque el catálogo del SAT es nacional, no dato de
 * un negocio—.
 *
 * `person_type` se deriva del RFC (12 = moral, 13 = física) y se guarda para poder validar el régimen sin recalcular.
 *
 * ## Direcciones (0..N), en COLUMNAS mexicanas, sin JSON
 *
 * SIN JSON en datos de dominio (ADR): calle, número exterior/interior, colonia, municipio, estado, CP, país fijo MX,
 * referencia. La dirección de ENVÍO es cosa aparte del CP fiscal, que vive en el perfil fiscal (ADR-005).
 *
 * ## «Uno predeterminado» con centinela
 *
 * Tanto un perfil fiscal como una dirección pueden marcarse como predeterminados, y sólo uno por cliente. Se garantiza
 * con una columna generada única —`IF(is_default, customer_id, NULL)`— el mismo patrón que el plano por omisión de una
 * sucursal (D78): MySQL no deduplica NULL, así que los no-predeterminados no compiten, y a lo sumo hay un predeterminado.
 *
 * La columna es **VIRTUAL**, no STORED, y no por capricho: MySQL prohíbe `ON DELETE CASCADE` en una columna
 * (`customer_id`) que una columna generada STORED referencia (1215), y aquí la cascada importa —al borrar un cliente se
 * van sus perfiles y direcciones—. Una columna VIRTUAL no tiene esa restricción y admite el índice único igual.
 *
 * ## Cumpleaños
 *
 * Fecha completa nullable en `customers` (D43). «Cumpleañeros del mes» filtra por `MONTH(birthday)`. Se guarda el año
 * porque cuesta lo mismo; quien no lo sepa deja null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->date('birthday')->nullable()->after('email');
        });

        Schema::create('customer_fiscal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // RFC en claro y buscable (D77): un CFDI lo necesita legible, y cifrarlo exigiría índices ciegos. En
            // mayúsculas y sin espacios; vacío se normaliza a null antes de llegar (Form Request).
            $table->char('rfc', 13)->charset('ascii')->collation('ascii_bin');
            $table->enum('person_type', ['fisica', 'moral']);

            $table->string('business_name', 200);      // razón social a la letra del SAT
            $table->char('postal_code', 5)->charset('ascii')->collation('ascii_bin'); // CP fiscal del receptor
            $table->char('tax_regime_code', 4)->charset('ascii')->collation('ascii_bin'); // c_RegimenFiscal
            $table->char('cfdi_use_code', 5)->charset('ascii')->collation('ascii_bin');   // c_UsoCFDI

            $table->boolean('is_default')->default(false);

            // «Uno predeterminado por cliente» con centinela: la columna vale `customer_id` cuando es el predeterminado
            // y NULL si no; su índice único deja pasar muchos NULL pero un solo predeterminado (D78). VIRTUAL para no
            // chocar con la cascada de `customer_id` (ver docblock).
            $table->unsignedBigInteger('default_customer_key')
                ->virtualAs('IF(is_default = 1, customer_id, NULL)')
                ->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'customer_fiscal_profiles_ulid_unique');

            // El mismo RFC no se repite dos veces en el mismo cliente (un cliente, un perfil por RFC).
            $table->unique(['customer_id', 'rfc'], 'customer_fiscal_profiles_customer_rfc_unique');

            $table->unique('default_customer_key', 'customer_fiscal_profiles_default_unique');

            $table->index(['tenant_id', 'customer_id'], 'customer_fiscal_profiles_tenant_customer_index');
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('label', 60)->nullable();     // «Casa», «Oficina»
            $table->string('street', 160);
            $table->string('exterior_number', 30);
            $table->string('interior_number', 30)->nullable();
            $table->string('neighborhood', 120);          // colonia
            $table->string('municipality', 120);          // municipio / alcaldía
            $table->string('state', 120);
            $table->char('postal_code', 5)->charset('ascii')->collation('ascii_bin');
            $table->char('country', 2)->charset('ascii')->collation('ascii_bin')->default('MX');
            $table->string('reference', 200)->nullable();

            $table->boolean('is_default')->default(false);

            // «Una dirección predeterminada por cliente», mismo centinela que los perfiles fiscales.
            $table->unsignedBigInteger('default_customer_key')
                ->virtualAs('IF(is_default = 1, customer_id, NULL)')
                ->nullable();

            $table->timestamps();

            $table->unique('ulid', 'customer_addresses_ulid_unique');
            $table->unique('default_customer_key', 'customer_addresses_default_unique');
            $table->index(['tenant_id', 'customer_id'], 'customer_addresses_tenant_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_fiscal_profiles');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('birthday');
        });
    }
};
