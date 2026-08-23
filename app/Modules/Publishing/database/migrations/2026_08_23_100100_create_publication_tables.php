<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa de publicación compartida (Iteración 8, Tanda A, ADR-007).
 *
 * Enriquece los artículos del Core con lo que la vitrina —menú o tienda— necesita, **sin duplicarlos**: cada fila apunta
 * al artículo por FK. Vive en el módulo `Publishing` (no activable) para que Menús y Tienda la compartan sin depender uno
 * del otro. Lo exclusivo de cada canal (SEO, política de stock, precio por canal) NO entra aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_publications', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();

            // Prosa, no JSON: la descripción larga del artículo para la vitrina.
            $table->text('long_description')->nullable();
            // Orden de aparición dentro de su categoría en la vitrina.
            $table->integer('sort_order')->default(0);
            // Ocultar de la publicación sin tocar el catálogo ni la disponibilidad del POS.
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            $table->unique('ulid', 'article_publications_ulid_unique');
            // Una publicación por artículo.
            $table->unique(['tenant_id', 'article_id'], 'article_publications_article_unique');
            // «Los artículos visibles del negocio», para pintar la vitrina.
            $table->index(['tenant_id', 'is_visible'], 'article_publications_visible_index');
        });

        Schema::create('article_images', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();

            // Ruta en el disco `public`: una foto de producto es contenido público por naturaleza (se sirve al teléfono del
            // cliente), no un archivo sensible como un export. La ruta lleva ULID para que no se pueda enumerar.
            $table->string('disk_path', 255);
            $table->string('alt_text', 160)->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'article_images_ulid_unique');
            // La galería de un artículo en orden; la primera (menor orden) es la portada.
            $table->index(['tenant_id', 'article_id', 'sort_order'], 'article_images_gallery_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_images');
        Schema::dropIfExists('article_publications');
    }
};
