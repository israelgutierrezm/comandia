<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaces para que un cliente de la tienda cree una contraseña nueva (D381).
 *
 * No es el broker de Laravel: su `password_reset_tokens` usa el correo como llave, y el correo de un cliente se repite
 * entre negocios (es único POR negocio, D42) y puede ser también de alguien del personal. Aquí cada enlace es de un
 * cliente de un negocio, bajo el `TenantScope`.
 *
 * - `token_hash`: el token sólo existe en claro en el correo; aquí, su SHA-256. `unique` porque restablecer busca por él
 *   (con el `tenant_id` del slug como filtro extra).
 * - `unique(customer_id)`: un solo enlace vivo por cliente, impuesto por la base. Pedir otro reemplaza la fila; usarlo la
 *   borra. Sirve además a la FK y a `revokeFor`.
 * - `email`: el buzón al que se mandó, del largo de `customers.email`. Si el correo del cliente cambia por cualquier
 *   camino, el enlace muere aunque nadie lo haya revocado.
 * - Sin índice compuesto por `tenant_id`: ninguna consulta recorre la tabla por negocio; el índice de su FK sirve a la
 *   cascada al borrar el negocio y a la purga del demo.
 * - Sin `ulid` (la fila nunca sale del servidor) y sin `updated_at` (se reemplaza o se borra, no se edita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_password_resets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('email', 120);
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin');

            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->unique('customer_id', 'customer_password_resets_customer_unique');
            $table->unique('token_hash', 'customer_password_resets_token_unique');
        });

        DB::statement(
            'ALTER TABLE customer_password_resets ADD CONSTRAINT customer_password_resets_expiry '
            .'CHECK (expires_at > created_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_resets');
    }
};
