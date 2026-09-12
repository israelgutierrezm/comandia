<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-013: modo de tienda + opciones de entrega configurables + estados de envío.
 *
 * - `stores`: el MODO de fulfillment (`preparation` = A&B con comanda a cocina | `dispatch` = retail que
 *   empaca y envía después), y qué entregas ofrece (`offers_pickup`, `offers_shipping`).
 * - `orders`: datos del envío para el modo dispatch —paquetería, guía y fecha— y el sello de empacado.
 *
 * Relleno idempotente de las tiendas ya existentes para NO cambiar su comportamiento: modo `preparation`,
 * pickup encendido, y envío encendido sólo si ya tenían alguna zona activa (que es como se decidía hoy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('fulfillment_mode', 20)->default('preparation')->after('auto_accept_orders');
            $table->boolean('offers_pickup')->default(true)->after('fulfillment_mode');
            $table->boolean('offers_shipping')->default(false)->after('offers_pickup');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Sólo se llenan en modo envío. Sin índice: siempre se consultan por el pedido ya acotado.
            $table->string('carrier', 80)->nullable()->after('delivery_address');
            $table->string('tracking_number', 120)->nullable()->after('carrier');
            $table->timestamp('packed_at')->nullable()->after('ready_at');
            $table->timestamp('shipped_at')->nullable()->after('packed_at');
        });

        // `status` es un ENUM de MySQL: hay que ampliarlo con los estados del camino de envío (ADR-013).
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','accepted','ready','packed','shipped','completed','failed','rejected','cancelled'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);

        // Tiendas existentes: ofrecen envío si ya tenían una zona activa (así se gateaba antes). El resto
        // toma los defaults de columna (preparación + pickup). En la base de pruebas no hay tiendas al migrar.
        DB::table('stores')->update([
            'offers_shipping' => DB::raw(
                'case when exists (select 1 from shipping_zones z '.
                'where z.store_id = stores.id and z.is_active = 1) then 1 else 0 end'
            ),
        ]);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'pending_payment','paid','accepted','ready','completed','failed','rejected','cancelled'
            ) NOT NULL DEFAULT 'pending_payment'
        SQL);

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['carrier', 'tracking_number', 'packed_at', 'shipped_at']);
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['fulfillment_mode', 'offers_pickup', 'offers_shipping']);
        });
    }
};
