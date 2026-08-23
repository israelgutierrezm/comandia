<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de la tienda en línea (Iteración 8, Tanda C, D48/D51).
 *
 * El carrito se materializa en un pedido con sus **totales e importes de línea congelados** (como el POS congela el precio
 * al comandar). El pedido es un documento **foliado** por (tenant, sucursal, tipo, serie) sin huecos (§7). Nace
 * `pending_payment`; el pago (Tanda C parte 3) lo pasa a `paid` y dispara el ciclo financiero. Entrega: pickup o envío por
 * zona (el costo de la zona suma al total). Las líneas del pedido son inmutables; el pago, cuando llegue, también.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Zonas de envío: nombre + costo. La cobertura por código postal es evolución.
        Schema::create('shipping_zones', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            $table->string('name', 120);
            $table->decimal('cost', 12, 2);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('ulid', 'shipping_zones_ulid_unique');
            $table->index(['tenant_id', 'store_id'], 'shipping_zones_store_index');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            // Folio por sucursal (§7): sin huecos, bajo lock.
            $table->char('series', 8)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('order_number');

            $table->enum('delivery_type', ['pickup', 'shipping']);
            $table->foreignId('shipping_zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();
            $table->decimal('shipping_cost', 12, 2)->default('0.00');
            $table->string('delivery_address', 300)->nullable();

            // Importes congelados (IVA incluido, D30), como el POS.
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);

            $table->enum('status', ['pending_payment', 'paid', 'failed', 'cancelled'])->default('pending_payment');
            $table->string('notes', 300)->nullable();

            // La pasarela y su referencia se fijan al iniciar el pago (parte 3).
            $table->string('gateway', 40)->nullable();
            $table->string('gateway_reference', 191)->nullable();

            $table->timestamp('placed_at')->useCurrent();
            $table->timestamps();

            $table->unique('ulid', 'orders_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'series', 'order_number'], 'orders_folio_unique');
            $table->index(['tenant_id', 'status'], 'orders_status_index');
            $table->index(['tenant_id', 'customer_id'], 'orders_customer_index');
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();

            // Congelados al crear el pedido: si el nombre o el precio cambian después, el pedido no.
            $table->string('name', 120);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2);

            $table->index(['tenant_id', 'order_id'], 'order_items_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('shipping_zones');
    }
};
