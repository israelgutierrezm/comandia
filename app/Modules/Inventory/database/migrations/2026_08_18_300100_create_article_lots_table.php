<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `article_lots` — lotes y caducidades, opcionales por artículo (D23).
 *
 * ## Por qué esta tabla llega en el paso 1 y su lógica en el paso 3
 *
 * El plan pone FEFO en el paso 3, y ahí sigue: la **selección** de lotes al dar salida, los endpoints de
 * administración y la bandera `articles.tracks_lots` son de ese paso.
 *
 * La tabla tiene que existir antes porque de ella dependen la forma de `stock_movements` —que lleva
 * `lot_id`— y, sobre todo, la **unicidad** de `article_stocks`, cuya llave incluye el lote. Crearla después
 * exigiría rehacer un índice único sobre una tabla ya con datos, y eso es peor que adelantar catorce líneas
 * de esquema.
 *
 * Mientras no exista el paso 3, `lot_id` es siempre `NULL` y todo funciona: un artículo sin lotes tiene un
 * solo saldo por almacén.
 *
 * ## El lote es del ARTÍCULO, no del artículo en un almacén (P3)
 *
 * El mismo lote de leche puede estar repartido entre la matriz y Polanco, y su caducidad es la misma en los
 * dos sitios: es una propiedad del lote. El saldo por almacén lo lleva `article_stocks`.
 *
 * La alternativa —lote por almacén— duplicaría `expires_at` en cada almacén, con el riesgo evidente de que
 * se capturen fechas distintas para el mismo lote físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_lots', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT: un lote citado por movimientos de inventario no puede desaparecer porque el
            // artículo se borre. Los artículos se archivan (D80).
            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // El código que trae el proveedor impreso en la caja. `ascii_bin` (D58): `L-2026A` y `l-2026a`
            // son lotes distintos, y una colación acento-insensible los mezclaría.
            $table->string('code', 40)->charset('ascii')->collation('ascii_bin');

            // NULL = no caduca. Es distinto de una fecha muy lejana: la sal no caduca, y ponerle el año
            // 2099 sería inventar un dato que después alguien leería como real.
            $table->date('expires_at')->nullable();

            $table->date('received_at');

            $table->enum('status', ['active', 'depleted', 'expired'])->default('active');

            $table->timestamps();

            $table->unique('ulid', 'article_lots_ulid_unique');

            // El código del lote es único POR ARTÍCULO, no por tenant: dos proveedores distintos pueden
            // usar la misma nomenclatura para productos que no tienen nada que ver.
            $table->unique(['tenant_id', 'article_id', 'code'], 'article_lots_tenant_article_code_unique');

            // FEFO: «los lotes de este artículo, el que caduca primero al frente». Es LA consulta de la
            // salida de inventario cuando el artículo lleva lotes, y sin índice sería un recorrido por cada
            // salida — o sea por cada venta.
            //
            // `status` va incluido porque la salida sólo considera lotes activos: sin él, el índice traería
            // los agotados y habría que descartarlos leyendo filas.
            $table->index(
                ['tenant_id', 'article_id', 'status', 'expires_at'],
                'article_lots_fefo_index'
            );
        });

        // Un lote recibido no puede caducar antes de recibirse. No es una hipótesis: es un error de captura
        // frecuente —teclear el año anterior— y con FEFO ese lote se saldría PRIMERO, vaciando lo que sí
        // servía y dejando en el almacén lo que caduca de verdad.
        DB::statement(<<<'SQL'
            ALTER TABLE `article_lots`
            ADD CONSTRAINT `chk_article_lots_expiry_after_receipt`
            CHECK (`expires_at` IS NULL OR `expires_at` >= `received_at`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('article_lots');
    }
};
