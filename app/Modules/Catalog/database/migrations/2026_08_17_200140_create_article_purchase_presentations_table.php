<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_purchase_presentations` — presentaciones de compra (D22).
 *
 * "Costal de 25 kg", "Caja con 24". Van en esta iteración y no en la 3 —donde están las compras—
 * porque **la captura manual de costo las necesita ya**: "compré un costal de 25 kg en $600" es la
 * forma en que un dueño piensa el costo, y sin presentación habría que pedirle que divida a mano.
 * Ése es justo el cálculo donde se equivoca, y un costo unitario mal capturado contamina el costeo
 * de todo lo que use ese insumo.
 *
 * ## No lleva `unit_id`, y no es un olvido
 *
 * `quantity_in_base_unit` está en la unidad base del artículo: la presentación es un **múltiplo**,
 * no otra unidad. Un costal de 25 kg de un artículo cuya base es `kg` vale 25; de uno cuya base es
 * `g`, vale 25000. Poner una unidad aquí abriría la puerta a que la presentación estuviera en una
 * dimensión distinta a la del artículo.
 *
 * Es también la vía legítima de la conversión entre dimensiones que `units` prohíbe globalmente:
 * "una caja tiene 24 piezas" sólo es cierto por artículo, y éste es el único nivel donde la
 * afirmación se puede hacer sin mentir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_purchase_presentations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->cascadeOnDelete();

            $table->string('name', 80);

            // DECIMAL(12,4) como toda cantidad del sistema (§7).
            $table->decimal('quantity_in_base_unit', 12, 4)->unsigned();

            $table->string('barcode', 32)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->boolean('is_default')->default(false);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'article_purchase_presentations_ulid_unique');

            // "Las presentaciones activas de este artículo": el selector de la captura de costo y,
            // en la Iteración 3, el de la recepción de compra.
            $table->index(
                ['tenant_id', 'article_id', 'status'],
                'article_presentations_tenant_article_index'
            );

            // Lectura de código de barras: llega un código y hay que resolver artículo y factor.
            // Sin índice, cada lectura recorrería la tabla completa.
            $table->index(['tenant_id', 'barcode'], 'article_presentations_tenant_barcode_index');
        });

        // Una presentación de cantidad 0 haría que el costo unitario fuera una división por cero.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_purchase_presentations`
            ADD CONSTRAINT `chk_article_presentations_quantity_positive` CHECK (
                `quantity_in_base_unit` > 0
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_purchase_presentations');
    }
};
