<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `purchase_receipt_lines` — **aquí se cobra lo construido en la Iteración 2.**
 *
 * Se recibe «3 cajas de 12 kg» y el sistema guarda las 36 000 g. La presentación de compra hace la conversión, y ésta
 * es la primera vez que sirve para algo.
 *
 * ## La conversión se CONGELA
 *
 * `quantity_in_base_unit` se calcula al capturar la línea y se guarda. La cantidad de una presentación es inmutable
 * (D147) y la presentación se puede dar de baja, pero el movimiento de inventario tiene que seguir siendo legible
 * dentro de tres años — y sobre todo, tiene que seguir **cuadrando** con el saldo que produjo. Recalcularla desde la
 * presentación al leer haría que un cambio de catálogo reinterpretara mercancía que ya entró.
 *
 * Y se guardan las dos: `quantity` como se capturó (3) y `quantity_in_base_unit` convertida (36 000). La primera
 * explica la segunda, que es lo que alguien necesita cuando el saldo no le cuadra con la factura.
 *
 * ## El IVA va POR LÍNEA
 *
 * Una factura mezcla tasas: alimentos preparados al 16 % y despensa al 0 %, en el mismo papel. Guardar la tasa por
 * línea cuesta una columna y evita el problema que D150 dejó abierto del lado de las ventas — aquí la factura ya dice
 * la tasa de cada renglón, así que no hay nada que adivinar.
 *
 * `unit_price` es **sin IVA**, siempre, porque es lo que dice el subtotal de la factura. Que el impuesto sea costo o no
 * lo decide `purchasing.vat_is_creditable` al confirmar, y el criterio usado queda congelado en la recepción.
 *
 * ## `lot_code` y `expires_at` como TEXTO, no como FK
 *
 * La línea captura el lote **como viene escrito en la caja**. El `article_lots` correspondiente se crea al confirmar,
 * no al capturar: un borrador que ya hubiera creado lotes dejaría lotes huérfanos si nunca se confirma, y un lote
 * huérfano aparece en los selectores de FEFO como si tuviera mercancía.
 *
 * Al confirmar se guarda además el `lot_id` resultante, para que el documento pueda decir exactamente de qué partida
 * física se trata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_lines', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: una línea no tiene vida sin su recepción. No colisiona con la columna generada (D156) porque ésa
            // se basa en `lot_id`.
            $table->foreignId('purchase_receipt_id')
                ->constrained('purchase_receipts')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // La presentación en la que llegó. `nullOnDelete` y no RESTRICT: es contexto explicativo, porque
            // `quantity_in_base_unit` ya está congelada y perder la presentación no invalida la cantidad.
            $table->foreignId('presentation_id')
                ->nullable()
                ->constrained('article_purchase_presentations')
                ->nullOnDelete();

            // Como se capturó, en la unidad de la presentación (3 cajas).
            $table->decimal('quantity', 12, 4);

            // Convertida y congelada, en la unidad base del artículo (36 000 g).
            $table->decimal('quantity_in_base_unit', 12, 4);

            // Precio SIN IVA, por unidad de captura: es lo que dice el renglón de la factura.
            $table->decimal('unit_price', 12, 4);

            // La tasa de esta línea, congelada. Por línea porque una factura mezcla tasas.
            $table->decimal('tax_rate', 5, 2);

            // Los tres importes del renglón, congelados: sin IVA, el impuesto, y el total. Se guardan porque son las
            // cifras con las que se cuadró la factura, y recalcularlas con otro redondeo daría otro número.
            $table->decimal('line_subtotal', 12, 2);
            $table->decimal('line_tax', 12, 2);
            $table->decimal('line_total', 12, 2);

            // El lote como viene escrito en la caja. Texto y no FK: el `article_lots` se crea al confirmar.
            $table->string('lot_code', 60)->nullable();
            $table->date('expires_at')->nullable();

            // El lote que se creó o se reusó al confirmar. RESTRICT y además por exigencia de MySQL: la columna
            // generada se basa en ella (D156).
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            // El movimiento de kardex que esta línea produjo. Hace navegable recepción → kardex y vuelve DETECTABLE una
            // confirmación a medias: una línea sin movimiento es una confirmación que se interrumpió.
            $table->foreignId('movement_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->timestamps();

            // Sin índice propio: la única consulta es «las líneas de esta recepción», y el índice único de abajo
            // empieza por (tenant_id, purchase_receipt_id) y la sirve (§7).
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `purchase_receipt_lines`
            ADD COLUMN `lot_key` VARCHAR(60) GENERATED ALWAYS AS (COALESCE(`lot_code`, '')) STORED
        SQL);

        // Un renglón por (recepción, artículo, presentación, lote). La presentación entra en la llave porque la misma
        // mercancía puede llegar en dos formatos en la misma factura —dos cajas y tres bolsas— y son renglones
        // legítimos; el lote, porque llega en varias partidas con caducidades distintas.
        //
        // Lo que esto SÍ impide es capturar dos veces el mismo renglón exacto, que es el dedazo que duplica existencia.
        DB::statement(<<<'SQL'
            ALTER TABLE `purchase_receipt_lines`
            ADD UNIQUE `purchase_receipt_lines_unique`
                (`tenant_id`, `purchase_receipt_id`, `article_id`, `presentation_id`, `lot_key`)
        SQL);

        // Cantidades y precios positivos. Es un CHECK y no una validación porque una línea de cero o negativa no es un
        // error recuperable: produciría un movimiento que no significa nada, o uno que suma cuando debería restar.
        DB::statement(<<<'SQL'
            ALTER TABLE `purchase_receipt_lines`
            ADD CONSTRAINT `chk_purchase_receipt_lines_positive` CHECK (
                `quantity` > 0 AND `quantity_in_base_unit` > 0 AND `unit_price` >= 0 AND `tax_rate` >= 0
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lines');
    }
};
