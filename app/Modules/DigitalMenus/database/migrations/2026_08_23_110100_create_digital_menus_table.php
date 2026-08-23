<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menús digitales (Iteración 8, Tanda A, ADR-007, D5).
 *
 * Un menú publicable **por sucursal**: se sirve en `/m/{slug}` (QR) y se puede generar en PDF. No cura el catálogo artículo
 * por artículo en v1 —muestra los vendibles/disponibles de la sucursal por categoría, con la capa de `Publishing` para
 * descripción y fotos—; la selección fina es evolución (`digital_menu_items`, deuda declarada).
 *
 * El `slug` es único **globalmente**, no sólo por tenant: la ruta pública no tiene sesión, así que el slug ES quien
 * resuelve el negocio. Un slug repetido entre negocios haría ambiguo a quién sirve `/m/{slug}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_menus', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->string('slug', 80);
            $table->boolean('is_active')->default(false);
            $table->boolean('show_prices')->default(true);

            // Tema para el PDF y la portada pública.
            $table->char('theme_primary', 7)->default('#1c1917'); // hex
            $table->string('theme_logo_path', 255)->nullable();
            $table->string('theme_font', 60)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'digital_menus_ulid_unique');
            // Global: el slug resuelve el tenant en la ruta pública sin sesión.
            $table->unique('slug', 'digital_menus_slug_unique');
            // Un menú por sucursal en v1.
            $table->unique(['tenant_id', 'branch_id'], 'digital_menus_branch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_menus');
    }
};
