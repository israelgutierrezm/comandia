<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierra la FK que el paso 7 dejó anotada.
 *
 * `pos_accounts.customer_id` nació sin clave foránea porque `customers` no existía todavía. La columna se creó desde
 * entonces para no tener que hacer un `ALTER` sobre la tabla más consultada del sistema; la restricción se pone ahora.
 *
 * Es la segunda vez que se cierra una FK así —la primera fue `pos_session_id` en el paso 6— y las dos veces destapó
 * algo. Aquélla reveló que las pruebas del diario usaban un id de sesión inventado.
 *
 * ## La migración vive en `Customers` y no en `Pos`
 *
 * Porque es `customers` la tabla que llega tarde: el módulo que aparece es el que cierra el enlace. Al revés, `Pos`
 * tendría una migración que sólo puede correr si `Customers` ya migró, y el orden entre módulos dejaría de leerse en
 * las fechas de los archivos.
 *
 * ## `nullOnDelete` y no `restrict`
 *
 * Un cliente borrado no debería impedir consultar las cuentas que pagó, y esas cuentas siguen siendo válidas sin él: el
 * ticket ya se emitió y el dinero ya entró. Lo que se pierde es a quién se le atribuía, y eso es exactamente lo que
 * borrar un cliente significa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_accounts', function (Blueprint $table): void {
            $table->foreign('customer_id', 'pos_accounts_customer_id_foreign')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_accounts', function (Blueprint $table): void {
            $table->dropForeign('pos_accounts_customer_id_foreign');
        });
    }
};
