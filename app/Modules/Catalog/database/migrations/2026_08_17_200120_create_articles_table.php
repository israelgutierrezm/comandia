<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `articles` — el artículo unificado (D17).
 *
 * D17 prohíbe tablas separadas de "productos" e "insumos", y no es una preferencia de modelado: en
 * un restaurante la misma cosa es las dos. Una cerveza en botella se vende, se inventaría y además
 * es insumo de una michelada; una salsa preparada en tandas se inventaría, es insumo y es producible.
 * Ninguna de esas se puede expresar con un `type` único, así que las cuatro capacidades son cuatro
 * banderas independientes.
 *
 * ## El precio es CON IVA INCLUIDO (D30) y el desglose se calcula
 *
 *     subtotal = base_price / (1 + tasa)
 *     iva      = base_price − subtotal
 *
 * La tasa sale de la configuración jerárquica (`tax.vat_rate`, ámbito máximo sucursal). Ni el
 * subtotal ni el IVA se almacenan en ninguna parte: son consecuencias de un dato maestro y de un
 * ajuste, y guardarlos crearía una segunda fuente que quedaría desfasada el día que el tenant
 * cambie la tasa.
 *
 * ## Lo que esta tabla NO tiene, y no es olvido
 *
 * - **Costo.** Vive en el módulo `Costing`, que es su dueño (P1). Poner aquí una columna de costo
 *   haría que `Catalog` dependiera de `Costing`, justo el sentido que P1 prohíbe.
 * - **Tasa de IVA por artículo.** §6.1 y D30 la definen por tenant con override por sucursal.
 *   Queda como riesgo documentado para negocios de tasa mixta (P7).
 * - **Área de preparación** para el ruteo de comandas y **ventanas de horario**: Iteración 4, donde
 *   existe el modelo de comanda y de menú que les da semántica.
 * - **Imágenes y descripción larga**: capa de publicación de ADR-007, Iteración 9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // SKU OPCIONAL (P10): un restaurante no le pone código a "Enchiladas suizas", y
            // obligarlo produce códigos inventados que nadie usa. Nullable + unique funciona porque
            // MySQL no deduplica NULL — aquí eso es exactamente lo deseado.
            $table->string('code', 40)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->string('name', 160);

            // Una comanda es papel de 58 mm. "Enchiladas suizas de pollo con frijoles refritos" no
            // cabe, y dejar que el POS trunque produce comandas ambiguas para la cocina, que es
            // justo donde una ambigüedad cuesta un platillo. Nullable: si falta, se usa `name`.
            $table->string('short_name', 40)->nullable();

            // RESTRICT: una categoría con artículos no se borra. Nullable porque para un insumo la
            // categoría no aporta —la harina no necesita categoría de venta— y obligatoria para
            // vendibles por regla de aplicación (P11), no por CHECK: la regla depende de otra
            // columna y de un flujo de alta en dos pasos.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('article_categories')
                ->restrictOnDelete();

            // La unidad en la que se expresan TODAS las cantidades de este artículo (§7). RESTRICT
            // porque cambiarla o borrarla reinterpretaría el histórico completo de cantidades.
            $table->foreignId('base_unit_id')
                ->constrained('units')
                ->restrictOnDelete();

            // ---- Capacidades (D17) ----
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_inventoriable')->default(false);
            $table->boolean('is_supply')->default(false);
            $table->boolean('is_producible')->default(false);

            // Dato maestro, IVA incluido (D30). Nullable: se permite capturar un artículo y ponerle
            // precio después, pero no venderlo sin precio (invariante I2).
            $table->decimal('base_price', 12, 2)->nullable();

            // Override del markup del tenant. MARKUP = utilidad ÷ costo (D13, §7): el porcentaje
            // con el que el sistema SUGIERE precio. El margen —utilidad ÷ precio— no se almacena
            // nunca: es una consecuencia del precio y del costo, y se calcula al leer.
            $table->decimal('markup_percent', 6, 2)->nullable();

            $table->boolean('is_available_in_pos')->default(true);

            // `archived` y no borrado (D80): hay historial de precios y de costos apuntando aquí.
            $table->enum('status', ['active', 'archived'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'articles_ulid_unique');
            $table->unique(['tenant_id', 'code'], 'articles_tenant_code_unique');

            // LA consulta más frecuente del sistema: el catálogo vendible del POS ordenado por
            // nombre. Cuatro columnas porque las tres primeras son igualdad y la cuarta es a la vez
            // el orden y el rango de la búsqueda por prefijo del selector de artículos.
            $table->index(
                ['tenant_id', 'status', 'is_sellable', 'name'],
                'articles_tenant_sellable_name_index'
            );

            // Navegación por categoría en POS y en administración, y base de las promociones por
            // categoría (D50, Iteración 7).
            $table->index(['tenant_id', 'category_id', 'status'], 'articles_tenant_category_index');

            // El selector de insumos al capturar una receta. Sin él, elegir un insumo recorre el
            // catálogo completo: en un restaurante con 800 artículos, el 90 % de la tabla.
            $table->index(['tenant_id', 'is_supply', 'status'], 'articles_tenant_supply_index');

            // NO se crean índices sobre `is_inventoriable` ni `is_producible`: sus consultas son de
            // administración, de baja frecuencia y sobre cientos de filas. "Podría servir" no es
            // justificación, y ningún índice va sin justificación escrita.
        });

        // Un precio negativo no es un descuento: es un artículo que paga al cliente por
        // llevárselo. Los descuentos tienen su propio permiso, motivo y auditoría (§6.3).
        DB::statement(<<<'SQL'
            ALTER TABLE `articles`
            ADD CONSTRAINT `chk_articles_price_not_negative` CHECK (
                `base_price` IS NULL OR `base_price` >= 0
            )
        SQL);

        // Un markup negativo significaría vender bajo costo por configuración y en silencio. Si el
        // negocio quiere hacerlo, fija el precio a mano: ahí la decisión queda registrada con actor
        // en el historial de precios, que es donde tiene que estar.
        DB::statement(<<<'SQL'
            ALTER TABLE `articles`
            ADD CONSTRAINT `chk_articles_markup_not_negative` CHECK (
                `markup_percent` IS NULL OR `markup_percent` >= 0
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
