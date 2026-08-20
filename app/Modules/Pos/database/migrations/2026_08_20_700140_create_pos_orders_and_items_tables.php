<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_orders`, `pos_order_items` y `pos_order_item_modifiers` — lo que se pidió y lo que se cobra.
 *
 * ## LOS CONGELADOS: la decisión que define este paso
 *
 * El precio, el nombre del artículo, la tasa de IVA y los modificadores con su precio se copian a la línea **al
 * capturarla**, y nunca se releen del catálogo. Si alguien sube el precio del café a media tarde, las cuentas abiertas
 * siguen cobrando el precio con el que se pidió — que es lo que el cliente aceptó al pedir.
 *
 * El **nombre** también, y eso sorprende hasta que se piensa en un ticket reimpreso un mes después: tiene que decir lo
 * que decía el original, aunque el artículo se haya renombrado o dado de baja.
 *
 * Y la **tasa de IVA** es el paso 2 de D150, que dejó la tasa por negocio con override por sucursal y fijó tres pasos si
 * aparece un cliente de tasas mixtas. Congelarla ahora hace que agregar `articles.vat_rate` después cueste una columna;
 * no congelarla haría que costara recalcular documentos ya emitidos, que es justo lo que D150 dice que no se puede
 * hacer.
 *
 * ## `line_total` es columna GENERADA
 *
 * `(quantity × (unit_price + modifiers_total)) − discount_amount`, calculado por la base. El mismo argumento de
 * `variance` y `balance_after` en la Iteración 3: un total calculado en PHP y guardado se desincroniza en cuanto alguien
 * escribe por otra vía. Y es una multiplicación de dinero, que es donde se cuela el error de redondeo (D134) — mejor
 * hacerla una vez y en un solo sitio.
 *
 * ## El item apunta a la CUENTA además de a la orden
 *
 * Está denormalizado a propósito. Mover un item entre cuentas (paso 12) cambia `pos_account_id`, y la orden se queda
 * donde estaba **porque describe lo que se preparó**: la comanda ya salió por la impresora de la cocina y ese hecho no
 * se puede mover. Sin esta columna, «qué se cobra en esta cuenta» exigiría recorrer órdenes cuyos items ya no le
 * pertenecen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('pos_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            // El número de la orden DENTRO de la cuenta: 1, 2, 3… No folia con `DocumentNumberAllocator` porque una
            // orden no es un documento con valor —es un envío a cocina— y foliar cada envío serializaría la captura por
            // sucursal, que es lo último que se quiere en hora pico.
            $table->unsignedSmallInteger('sequence');

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Cuándo se mandó a preparar. `null` = capturada y no comandada todavía.
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'pos_orders_ulid_unique');
            $table->unique(['pos_account_id', 'sequence'], 'pos_orders_account_sequence_unique');

            $table->index(['tenant_id', 'pos_account_id'], 'pos_orders_account_index');
        });

        Schema::create('pos_order_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE hacia la orden: un item no existe fuera del envío que lo creó.
            $table->foreignId('pos_order_id')
                ->constrained('pos_orders')
                ->cascadeOnDelete();

            // Y RESTRICT hacia la cuenta, que es lo que se cobra. Ver la nota sobre la denormalización.
            $table->foreignId('pos_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // El área que lo prepara, resuelta al comandar (paso 8). `null` mientras está capturado.
            $table->foreignId('preparation_area_id')
                ->nullable()
                ->constrained('preparation_areas')
                ->restrictOnDelete();

            $table->enum('status', ['captured', 'commanded', 'preparing', 'served', 'cancelled'])->default('captured');

            // Cuatro decimales: se puede vender media pizza o 250 g de queso al peso.
            $table->decimal('quantity', 12, 4);

            // ---- LOS CONGELADOS ----
            $table->string('article_name', 120);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('modifiers_total', 12, 2)->default(0);

            $table->decimal('discount_amount', 12, 2)->default(0);

            // Cortesía: venta en $0 que SÍ descuenta inventario (§6.3). La bandera vive en la línea porque el descuento
            // que la produce es un documento aparte, y el consumo de insumos necesita saberlo sin consultarlo.
            $table->boolean('is_courtesy')->default(false);

            $table->string('cancelled_reason', 300)->nullable();

            $table->foreignId('cancelled_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('cancelled_at')->nullable();

            // Qué se hizo con la comida al cancelar un item ya comandado (§6.3): merma si se preparó, devolver al
            // inventario si no se tocó. `none` cuando no aplica.
            $table->enum('cancellation_destination', ['none', 'waste', 'restock'])->nullable();

            $table->foreignId('captured_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique('ulid', 'pos_order_items_ulid_unique');

            // La cuenta en pantalla: sus items y su estado.
            $table->index(['tenant_id', 'pos_account_id', 'status'], 'pos_order_items_account_status_index');

            // «¿Qué falta en esta barra?», que es la consulta de la cocina.
            $table->index(['tenant_id', 'preparation_area_id', 'status'], 'pos_order_items_area_status_index');
        });

        // `line_total` como columna GENERADA. Va en un ALTER aparte porque depende de otras columnas de la misma
        // sentencia, que es el camino que ya usaron `stock_count_lines` y `transfer_lines`.
        DB::statement(
            'ALTER TABLE pos_order_items ADD COLUMN line_total DECIMAL(12,2) '
            .'GENERATED ALWAYS AS (ROUND(quantity * (unit_price + modifiers_total), 2) - discount_amount) STORED'
        );

        DB::statement('ALTER TABLE pos_order_items ADD CONSTRAINT pos_order_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE pos_order_items ADD CONSTRAINT pos_order_items_price_not_negative CHECK (unit_price >= 0)');
        DB::statement('ALTER TABLE pos_order_items ADD CONSTRAINT pos_order_items_discount_not_negative CHECK (discount_amount >= 0)');
        DB::statement('ALTER TABLE pos_order_items ADD CONSTRAINT pos_order_items_vat_rate_range CHECK (vat_rate >= 0 AND vat_rate <= 100)');

        Schema::create('pos_order_item_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('pos_order_item_id')
                ->constrained('pos_order_items')
                ->cascadeOnDelete();

            $table->foreignId('modifier_id')
                ->constrained('modifiers')
                ->restrictOnDelete();

            // Congelados, por lo mismo que en la línea: el ticket reimpreso debe decir lo que decía.
            $table->string('modifier_name', 80);
            $table->decimal('extra_price', 12, 2);

            // Los 3 shots de D7: un modificador con cantidad.
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->timestamps();

            $table->unique('ulid', 'pos_order_item_modifiers_ulid_unique');

            // Un modificador una vez por línea: pedir «con queso» dos veces en el mismo renglón es la cantidad, no dos
            // filas — y dos filas harían que el total dependiera de cuántas veces alguien tocó el botón.
            $table->unique(['pos_order_item_id', 'modifier_id'], 'pos_order_item_modifiers_unique');
        });

        DB::statement('ALTER TABLE pos_order_item_modifiers ADD CONSTRAINT pos_order_item_modifiers_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_item_modifiers');
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_orders');
    }
};
