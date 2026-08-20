<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_account_operations` y su detalle — qué se movió, de dónde a dónde y quién. INMUTABLE.
 *
 * ## Por qué existe esta tabla, en una frase
 *
 * Sin ella, **mover un item entre cuentas es indistinguible de haberlo capturado allí desde el principio**, y ése es el
 * hueco por el que se va la mercancía en un bar: se capturan cuatro cervezas en la cuenta de la mesa 3, se mueven tres
 * a una cuenta que después se cancela, y la mesa 3 paga una. Nada en `pos_order_items` delata el movimiento — la línea
 * simplemente está en otra cuenta.
 *
 * El detalle guarda `from_account_id` y `to_account_id` **por item**, y no sólo en la cabecera, porque una operación
 * puede mover items de varias procedencias a la vez: juntar tres cuentas en una es un solo hecho con tres orígenes.
 *
 * ## `detail_count` denormalizado
 *
 * La pantalla del historial lista operaciones y muestra «3 items»; sin la columna, cada renglón sería un `COUNT` sobre
 * el detalle. Es la misma clase de proyección que `article_stocks`, y con la misma condición: se escribe una vez, al
 * crear la operación, y la operación es inmutable — así que no se puede desincronizar.
 *
 * ## Append-only
 *
 * Una operación describe algo que ocurrió. Editarla sería reescribir la historia de los movimientos que existe
 * justamente para que no se pueda reescribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_account_operations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->enum('kind', ['split', 'merge', 'move_items', 'reopen']);

            $table->foreignId('source_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            // `null` en `reopen` —no hay destino— y en `split`, donde los destinos son varios y viven en el detalle.
            $table->foreignId('target_account_id')
                ->nullable()
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            $table->foreignId('performed_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Quién puso el PIN, cuando la operación lo exige. Reabrir una cuenta cerrada sí; mover items entre cuentas
            // abiertas no — es operación normal de un mesero.
            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('detail_count')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'pos_account_operations_ulid_unique');

            // «Qué le pasó a esta cuenta», que es la pregunta de cualquier revisión. Va por ORIGEN porque es donde
            // empieza el rastro; el destino se alcanza por el detalle.
            $table->index(['tenant_id', 'source_account_id'], 'pos_account_operations_source_index');

            // El reporte de operaciones por persona y fecha, hermano del de descuentos: las dos son zonas donde la
            // mercancía se mueve sin venderse.
            $table->index(['tenant_id', 'performed_by_membership_id', 'created_at'], 'pos_account_operations_actor_index');
        });

        Schema::create('pos_account_operation_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('operation_id')
                ->constrained('pos_account_operations')
                ->cascadeOnDelete();

            $table->foreignId('pos_order_item_id')
                ->constrained('pos_order_items')
                ->restrictOnDelete();

            $table->foreignId('from_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            $table->foreignId('to_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Un item aparece una sola vez por operación. Dos renglones del mismo item en el mismo movimiento serían
            // dos destinos contradictorios para la misma línea.
            $table->unique(['operation_id', 'pos_order_item_id'], 'pos_account_operation_items_unique');

            // «¿Este item se movió alguna vez, y desde dónde?» — la pregunta que cierra el hueco del bar.
            $table->index(['tenant_id', 'pos_order_item_id'], 'pos_account_operation_items_item_index');
        });

        // Un movimiento que va de una cuenta a la misma cuenta no movió nada.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_account_operation_items`
            ADD CONSTRAINT `pos_account_operation_items_distinct_chk` CHECK (`from_account_id` <> `to_account_id`)
        SQL);

        // `reopen` no tiene destino; `merge` y `move_items` sí. `split` lo deja nulo porque sus destinos son varios.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_account_operations`
            ADD CONSTRAINT `pos_account_operations_target_chk` CHECK (
                (`kind` IN ('merge', 'move_items') AND `target_account_id` IS NOT NULL) OR
                (`kind` IN ('reopen', 'split') AND `target_account_id` IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_account_operation_items');
        Schema::dropIfExists('pos_account_operations');
    }
};
