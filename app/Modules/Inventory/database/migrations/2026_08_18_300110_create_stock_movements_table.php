<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stock_movements` — el KARDEX, y la tabla más importante de la iteración. INMUTABLE (§7).
 *
 * §6.2: *«kardex como fuente de verdad; existencia como acumulado»*. Es la misma forma que el costeo de la
 * Iteración 2 —historial inmutable más proyección— y por la misma razón: **el saldo se puede reconstruir, el
 * movimiento no**. Nunca se corrige un movimiento; se registra el contrario. Es lo que hace que un
 * inventario mal capturado tenga historia en lugar de tener otro número.
 *
 * ## `quantity` SIEMPRE positiva, con la dirección aparte
 *
 * Una cantidad con signo parece más compacta y es una trampa: un `SUM(quantity)` que no mire el signo
 * devuelve un número **plausible y equivocado**, y ese error no revienta — se acumula en un reporte que
 * nadie sospecha. Con la dirección explícita, cualquier suma tiene que decidir qué hace con entradas y
 * salidas. Hay un CHECK que impide una cantidad de cero: un movimiento que no mueve nada es ruido en la
 * tabla que más crece.
 *
 * ## `balance_after` congelado en el movimiento (P1)
 *
 * Denormalización deliberada, con tres razones concretas:
 *
 *   1. El kardex se lee como un **estado de cuenta** —fila por fila, saldo a la derecha— sin acumular en el
 *      cliente ni en la consulta.
 *   2. Hace **auditable** la proyección: si `article_stocks` se desvía del último `balance_after`, hay un
 *      problema y se puede ver. Una proyección sin testigo es una proyección en la que hay que creer.
 *   3. «¿Cuánto tenía el 3 de marzo?» se lee en una fila, en lugar de sumar la historia completa.
 *
 * Su precio hay que decirlo: obliga a **serializar** las inserciones del mismo `(almacén, artículo, lote)`
 * con un lock pesimista sobre la fila de la proyección. Dos recepciones simultáneas del mismo artículo en el
 * mismo almacén se esperan unos milisegundos. En un negocio de alimentos eso no es contención real.
 *
 * ## Existencias NEGATIVAS permitidas
 *
 * No hay CHECK sobre `balance_after`, y es una decisión y no un olvido. §6.2: el POS nunca se bloquea por
 * inventario. La causa más común de un negativo no es un error de captura — es que el conteo va atrasado, y
 * el sistema no está para impedir que el negocio opere.
 *
 * ## `source_ulid` junto a `source_id`
 *
 * La lección de D151, aplicada desde el día uno en lugar de agregada después: la llave interna deja de
 * significar algo si el documento desaparece, y no se puede exponer por la API (D91). Se congela el
 * identificador público al registrar el movimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT en las tres: el kardex es evidencia de negocio y sobrevive al catálogo. Almacenes y
            // artículos se archivan (D80), no se borran.
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // NULL = el artículo no lleva lotes, que es el caso de la mayoría.
            $table->foreignId('lot_id')
                ->nullable()
                ->constrained('article_lots')
                ->restrictOnDelete();

            $table->enum('kind', StockMovementKind::values());
            $table->enum('direction', ['in', 'out']);

            $table->decimal('quantity', 12, 4)->unsigned();

            // El costo unitario CON EL QUE SE MOVIÓ, congelado. Nullable porque no todo movimiento tiene
            // costo conocido: un ajuste de un artículo sin costo capturado no puede inventarse uno.
            //
            // Cuatro decimales por la excepción declarada de P3 en §7: es un costo unitario, no un monto.
            $table->decimal('unit_cost', 12, 4)->unsigned()->nullable();

            // `quantity * unit_cost`, congelado como MONTO a dos decimales. Se guarda en lugar de
            // calcularse porque es lo que suman los reportes de valor de merma y de compras: recalcularlo en
            // cada lectura obligaría a repetir el redondeo, y dos redondeos distintos del mismo número es de
            // donde salen los descuadres de un peso.
            $table->decimal('total_cost', 12, 2)->unsigned()->nullable();

            // El saldo del (almacén, artículo, lote) DESPUÉS de este movimiento. Firmado: puede ser
            // negativo, y esa es la decisión de §6.2.
            $table->decimal('balance_after', 12, 4);

            // El documento que causó el movimiento. Los tres juntos: tipo y llave interna para unir por SQL,
            // y ULID público para poder mostrarlo y para que el asiento siga siendo legible si el documento
            // se va (D151).
            $table->string('source_type', 120)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->char('source_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();

            // Idempotencia de jobs hecha columna (CLAUDE.md). El descuento por venta es asíncrono, así que
            // re-despachar el job NO puede duplicar el movimiento. El índice único lo hace imposible aunque
            // el código se equivoque.
            $table->string('idempotency_key', 120)->charset('ascii')->collation('ascii_bin')->nullable();

            // NULL = lo movió un job y no una persona. No se inventa un actor: uno falso en un registro de
            // evidencia es indistinguible de uno real.
            $table->foreignId('actor_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->string('notes', 200)->nullable();

            // Cuándo OCURRIÓ, que puede no ser cuándo se capturó: una recepción del viernes se registra el
            // lunes con la fecha del viernes.
            $table->timestamp('occurred_at');

            // Tabla inmutable: sólo `created_at`. El trait `Immutable` lo escribe desde PHP porque el reloj
            // de MySQL puede no estar en UTC (D85).
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'stock_movements_ulid_unique');

            // ---- Índices, cada uno con su consulta ----

            // El kardex de un artículo en un almacén, en orden. Es la consulta de la pantalla de kardex y la
            // que lee el último saldo para reconstruir la proyección.
            $table->index(
                ['tenant_id', 'warehouse_id', 'article_id', 'occurred_at'],
                'stock_movements_kardex_index'
            );

            // El mismo artículo en TODOS los almacenes: «¿dónde está mi queso?».
            $table->index(
                ['tenant_id', 'article_id', 'occurred_at'],
                'stock_movements_tenant_article_index'
            );

            // Trazabilidad inversa: «¿qué movimientos generó esta recepción?». Sin índice sería un recorrido
            // completo de la tabla más grande del sistema, y es la consulta que se hace al investigar.
            $table->index(
                ['tenant_id', 'source_type', 'source_id'],
                'stock_movements_source_index'
            );

            // Reportes por tipo: las mermas del mes, los ajustes del periodo.
            $table->index(
                ['tenant_id', 'kind', 'occurred_at'],
                'stock_movements_tenant_kind_index'
            );

            $table->unique(['tenant_id', 'idempotency_key'], 'stock_movements_idempotency_unique');
        });

        // Un movimiento de cantidad cero no mueve nada: sería ruido en la tabla que más crece, y un kardex
        // con filas que no cambian el saldo es más difícil de leer, no más completo.
        //
        // NO hay CHECK sobre `balance_after`: las existencias negativas están permitidas (§6.2).
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `chk_stock_movements_quantity_positive` CHECK (`quantity` > 0)
        SQL);

        // La dirección de la mayoría de los tipos es fija: una merma no puede sumar existencia. El dominio lo
        // impone y este CHECK lo hace estructural, para que un seeder, una importación o un `DB::table()`
        // tampoco puedan registrar una merma que suma.
        //
        // Sólo los dos ajustes admiten las dos direcciones, porque ahí el signo ES la información.
        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `chk_stock_movements_direction_matches_kind` CHECK (
                (`kind` IN ('purchase_receipt','transfer_in','production_in','sale_return','initial_load')
                    AND `direction` = 'in')
                OR (`kind` IN ('transfer_out','production_out','sale_consumption','waste')
                    AND `direction` = 'out')
                OR `kind` IN ('count_adjustment','manual_adjustment')
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
