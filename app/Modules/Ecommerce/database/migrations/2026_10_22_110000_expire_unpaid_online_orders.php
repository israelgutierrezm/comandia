<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos en línea que nunca se pagaron (D380).
 *
 * - `orders.status` gana `expired` («Pago vencido»): el pedido que a los 45 minutos sigue sin pago. Terminal, y distinto de
 *   `cancelled`: el reporte tiene que poder separar el abandono del pago de una cancelación.
 * - `orders.expired_at`: cuándo venció, como los demás hitos del pedido.
 * - `coupon_redemptions.released_at`: el canje se DEVUELVE cuando el pedido termina sin pago. El renglón no se borra —es
 *   la historia de que se intentó—, pero deja de contar para el tope del cupón y para el de cada cliente.
 *
 * ## Sin índices nuevos
 *
 * La tarea que vence pedidos busca los `pending_payment` de un negocio por fecha: entra por `orders_status_index`
 * (`tenant_id`, `status`), y los pendientes de un negocio son unos cuantos. El tope por cliente de un cupón ya tiene su
 * índice (`coupon_redemptions_customer_index`); filtrar los devueltos no cambia cuántas filas toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','accepted','ready','packed','shipped','completed','failed','rejected','cancelled','expired'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('expired_at')->nullable()->after('rejected_at');
        });

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->timestamp('released_at')->nullable()->after('redeemed_at');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropColumn('released_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('expired_at');
        });

        // Un pedido vencido vuelve como cancelado: el ENUM anterior no tiene dónde ponerlo.
        DB::table('orders')->where('status', 'expired')->update(['status' => 'cancelled']);

        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','accepted','ready','packed','shipped','completed','failed','rejected','cancelled'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);
    }
};
