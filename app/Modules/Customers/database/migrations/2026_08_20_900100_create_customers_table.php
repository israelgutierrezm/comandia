<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `customers` — el mínimo que el cobro necesita (D235).
 *
 * ## Entra lo que el cobro necesita; NO entra el expediente del cliente
 *
 * El alcance del módulo son perfiles fiscales, direcciones, cumpleaños y notas. Nada de eso hace falta para fiar un
 * consumo, y todo eso llega en la Iteración 7. Aquí entra sólo lo que el crédito exige: alguien con nombre a quien
 * atribuir un saldo.
 *
 * ## Alta express: nombre y teléfono (D43)
 *
 * Todo lo demás es nullable **a propósito**. Pedirle la razón social a alguien que está pagando un café es exactamente
 * lo que hace que nadie registre clientes — y un sistema de crédito sin clientes registrados es el fiado en una libreta,
 * que es lo que se está sustituyendo.
 *
 * ## El teléfono es único cuando existe
 *
 * Es el identificador con el que se busca a alguien en el mostrador: «¿a nombre de quién?» «del cinco cinco…». Dos
 * clientes con el mismo teléfono son un error de captura que acaba con el saldo de uno cargado al otro.
 *
 * En MySQL un índice único **no** deduplica NULL, así que los clientes sin teléfono —que serán muchos— no compiten entre
 * sí. Es la misma propiedad que en las reglas de ruteo del paso 8 jugó a favor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // El código interno del negocio, si lo usa. Nullable porque una fonda no numera a sus clientes.
            $table->char('code', 20)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('notes', 300)->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'customers_ulid_unique');
            $table->unique(['tenant_id', 'phone'], 'customers_tenant_phone_unique');
            $table->unique(['tenant_id', 'code'], 'customers_tenant_code_unique');

            // La búsqueda del mostrador es por NOMBRE, y es la consulta que corre mientras alguien espera con la
            // tarjeta en la mano.
            $table->index(['tenant_id', 'status', 'name'], 'customers_tenant_status_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
