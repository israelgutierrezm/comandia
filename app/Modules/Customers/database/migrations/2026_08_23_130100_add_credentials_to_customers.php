<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de cliente para la tienda en línea (Iteración 8, Tanda C).
 *
 * Un cliente puede iniciar sesión en la tienda pública. La contraseña se guarda **hasheada** (cast `hashed`); un cliente
 * puede existir SIN credenciales (alta express del POS, D43) y activarlas al registrarse en la tienda. El correo es único
 * **por negocio** entre los que lo tienen (D42): es la llave de acceso. Los correos nulos no chocan (MySQL permite varios
 * NULL en un índice único), así que los clientes sin correo del POS conviven.
 *
 * Además, `created_by_membership_id` pasa a **nullable**: un cliente que se AUTO-registra en la tienda no lo creó ningún
 * miembro del personal. Antes era obligatorio porque todo cliente nacía en el POS o el admin (D43); la tienda estrena el
 * alta sin personal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('password')->nullable()->after('email');

            // El correo es la llave de login: único por negocio entre los no nulos.
            $table->unique(['tenant_id', 'email'], 'customers_tenant_email_unique');
        });

        // Nullable en dos pasos: hay que soltar la FK, cambiar la columna y volver a ponerla.
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['created_by_membership_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('created_by_membership_id')->nullable()->change();
            $table->foreign('created_by_membership_id')->references('id')->on('tenant_memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['created_by_membership_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('created_by_membership_id')->nullable(false)->change();
            $table->foreign('created_by_membership_id')->references('id')->on('tenant_memberships')->restrictOnDelete();

            $table->dropUnique('customers_tenant_email_unique');
            $table->dropColumn('password');
        });
    }
};
