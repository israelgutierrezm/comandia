<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canales de marketplace de delivery (ADR-015, Fase 1): DiDi Food / Uber Eats / Rappi.
 *
 * Un pedido de marketplace es OTRO canal que produce un `Order` y entra al mismo pipeline (ADR-007). Lo
 * nuevo es la configuración por sucursal/canal (encender/apagar + credenciales), el mapeo de ítems
 * externos a artículos, y la marca de procedencia en el pedido para idempotencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Config por (sucursal, canal): encender/apagar y credenciales. Espejo de payment_gateway_settings,
        // pero por sucursal y por canal (cada sucursal es una "tienda" en la plataforma). Secretos cifrados.
        Schema::create('delivery_channel_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->string('channel', 40);                 // didi_food | uber_eats | rappi | fake
            $table->boolean('is_active')->default(false);  // apagado hasta configurarlo y encenderlo
            $table->string('external_store_id', 120)->nullable(); // id de la tienda en la plataforma
            $table->text('api_key')->nullable();           // cifrada
            $table->text('api_secret')->nullable();        // cifrada
            $table->text('webhook_secret')->nullable();    // cifrada
            $table->decimal('commission_rate', 5, 2)->default('0.00'); // % que retiene la plataforma

            $table->timestamps();

            $table->unique('ulid', 'delivery_channel_settings_ulid_unique');
            // Una config por sucursal y canal.
            $table->unique(['tenant_id', 'branch_id', 'channel'], 'delivery_channel_settings_unique');
        });

        // Mapeo del menú: id de ítem en la plataforma → artículo de Comandia, por canal.
        Schema::create('marketplace_menu_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 40);
            $table->string('external_item_id', 191);
            $table->foreignId('article_id')->constrained('articles')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'external_item_id'], 'marketplace_menu_maps_unique');
            $table->index(['tenant_id', 'article_id'], 'marketplace_menu_maps_article_index');
        });

        // Procedencia del pedido: de qué canal vino y su id externo, para IDEMPOTENCIA del webhook (un mismo
        // pedido reenviado no se duplica). Nulos en pedidos de la tienda nativa.
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('channel', 40)->nullable()->after('gateway_reference');
            $table->string('external_order_id', 191)->nullable()->after('channel');

            $table->unique(['tenant_id', 'channel', 'external_order_id'], 'orders_marketplace_unique');
        });

        // `delivery_type` es un ENUM de MySQL: se amplía con el canal de marketplace (el repartidor es de la
        // plataforma, no hay pickup ni envío nuestro).
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `delivery_type` ENUM('pickup','shipping','marketplace') NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `orders` MODIFY COLUMN `delivery_type` ENUM('pickup','shipping') NOT NULL
        SQL);

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_marketplace_unique');
            $table->dropColumn(['channel', 'external_order_id']);
        });

        Schema::dropIfExists('marketplace_menu_maps');
        Schema::dropIfExists('delivery_channel_settings');
    }
};
