<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canje de cupones en el checkout (Iteración 8, Tanda D, D3 parte 2).
 *
 * El pedido gana el cupón que se le aplicó (`coupon_id`) y el descuento **de productos** (`discount_total`) —el envío gratis
 * pone el envío en cero, no descuenta productos (D342)—. `coupon_redemptions` es el log **inmutable** de cada canje: un
 * cupón por pedido (`unique(tenant, order)`), para contar usos y no aplicarlo dos veces. La `OnlineSale` se asienta neta
 * (`subtotal − discount_total`), así que el reporte de canal netea los cupones por tipo (ADR-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable: la mayoría de los pedidos no llevan cupón. `nullOnDelete`: quitar un cupón no borra los pedidos que
            // lo usaron —conservan su `discount_total`—.
            $table->foreignId('coupon_id')->nullable()->after('shipping_zone_id')->constrained('coupons')->nullOnDelete();
            $table->decimal('discount_total', 12, 2)->default('0.00')->after('subtotal');
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // El beneficio real del canje (descuento de productos o envío ahorrado), para «cuánto costaron los cupones».
            $table->decimal('amount_discounted', 12, 2);
            $table->timestamp('redeemed_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'coupon_redemptions_ulid_unique');
            // Un cupón por pedido: aplicar dos veces al mismo pedido choca con la llave.
            $table->unique(['tenant_id', 'order_id'], 'coupon_redemptions_order_unique');
            // «Cuántas veces usó este cliente este cupón», para el límite por cliente.
            $table->index(['tenant_id', 'coupon_id', 'customer_id'], 'coupon_redemptions_customer_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('discount_total');
        });
    }
};
