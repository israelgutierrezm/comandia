<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tienda en línea (Iteración 8, Tanda B, ADR-007, D48).
 *
 * Lo EXCLUSIVO de la tienda: su configuración pública, qué sucursales atiende y los ajustes de tienda por artículo (SEO,
 * política de stock, precio por canal, visibilidad). La descripción y las fotos NO viven aquí —son de `Publishing`,
 * compartidas con el menú—. El artículo, el precio base y el inventario son del Core; esto sólo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Una tienda por negocio (D48: el cliente elige la sucursal al comprar).
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('slug', 80);
            $table->string('name', 120);
            $table->boolean('is_active')->default(false);
            $table->char('theme_primary', 7)->default('#1c1917');

            $table->timestamps();

            $table->unique('ulid', 'stores_ulid_unique');
            // Global: la ruta pública `/t/{slug}` resuelve el negocio por el slug, sin sesión.
            $table->unique('slug', 'stores_slug_unique');
            $table->unique('tenant_id', 'stores_tenant_unique');
        });

        // Las sucursales que la tienda atiende (subconjunto configurable): el cliente elige entre éstas.
        Schema::create('store_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->unique(['store_id', 'branch_id'], 'store_branches_unique');
            $table->index(['tenant_id', 'store_id'], 'store_branches_store_index');
        });

        // Ajustes de tienda por artículo: lo que la tienda agrega al artículo del Core.
        Schema::create('article_store_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();

            // La tienda SÍ respeta stock (ADR-007), configurable por artículo.
            $table->enum('stock_policy', ['sell_always', 'hide', 'mark_out_of_stock'])->default('sell_always');
            $table->boolean('is_in_store')->default(false);

            // SEO propio de la tienda (el menú no lo usa).
            $table->string('seo_title', 160)->nullable();
            $table->string('seo_description', 300)->nullable();

            // Override de precio POR CANAL (D-nuevo): si está, gana sobre el precio de sucursal; si no, hereda el del Core.
            $table->decimal('channel_price', 12, 2)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'article_store_settings_ulid_unique');
            $table->unique(['tenant_id', 'article_id'], 'article_store_settings_article_unique');
            // «Los artículos que están en la tienda», para pintar el catálogo.
            $table->index(['tenant_id', 'is_in_store'], 'article_store_settings_in_store_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_store_settings');
        Schema::dropIfExists('store_branches');
        Schema::dropIfExists('stores');
    }
};
