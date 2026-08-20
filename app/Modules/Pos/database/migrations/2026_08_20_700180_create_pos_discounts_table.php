<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_discounts` — la zona de máxima auditoría (§6.3). INMUTABLE.
 *
 * ## Por qué esta tabla es distinta de las demás
 *
 * Un descuento es la forma más fácil de sacar dinero de un restaurante sin que parezca robo: se cobra el total, se
 * aplica un descuento después, y la diferencia se queda en el bolsillo. §6.3 lo llama «zona de máxima auditoría» y §9 lo
 * nombra entre las mitigaciones del robo hormiga.
 *
 * De ahí tres cosas que no se negocian:
 *
 * 1. **El motivo es obligatorio**, con el mismo argumento que las mermas (D27): un descuento sin motivo es dinero que
 *    nadie puede explicar. Y no admite cadena vacía — un NOT NULL que acepta `''` no es un motivo obligatorio.
 * 2. **Exige PIN**, y se guardan las DOS personas: quien lo aplicó y quien lo autorizó. Que sean columnas distintas es
 *    el punto entero: el patrón que el reporte busca es «el mismo mesero pidiendo autorización veinte veces por turno».
 * 3. **`resulting_amount` lo calcula el servidor.** Si el cliente mandara el monto, un «10 %» podría llegar como
 *    cualquier cifra.
 *
 * ## Append-only
 *
 * No está en la lista de inmutables de §7, y aquí se declara como tal por lo mismo que los pagos: editar un descuento
 * ya aplicado cambiaría lo que el corte de anoche explicó. Corregirlo es aplicar otro descuento —o cobrar la
 * diferencia—, nunca reescribir el original.
 *
 * ## `value` y `resulting_amount` son dos cosas distintas
 *
 * `value` es lo que se pidió: `10` para un 10 %, `50.00` para cincuenta pesos. `resulting_amount` es lo que costó, ya
 * calculado. Guardar sólo el resultado perdería la intención —«¿fue un 10 % o cincuenta pesos?»— y guardar sólo el
 * valor obligaría a recalcular sobre una base que pudo cambiar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_discounts', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('pos_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            // `null` = descuento de CUENTA. Con una columna aparte —`scope`— habría dos fuentes para la misma verdad y
            // podrían contradecirse; con el nullable, la ausencia de item ES el alcance.
            $table->foreignId('pos_order_item_id')
                ->nullable()
                ->constrained('pos_order_items')
                ->restrictOnDelete();

            $table->enum('kind', ['percentage', 'amount', 'courtesy']);

            $table->decimal('value', 12, 2);

            $table->decimal('resulting_amount', 12, 2);

            // NOT NULL y con CHECK de longitud: un motivo vacío no es un motivo.
            $table->string('reason', 300);

            $table->foreignId('applied_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Quién puso el PIN. Nullable en el esquema porque el día que exista un descuento automático —una promoción
            // de la Iteración 7— no habrá humano autorizando; hoy el servicio lo exige siempre.
            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'pos_discounts_ulid_unique');

            // «Qué descuentos lleva esta cuenta», que es lo que el ticket desglosa y lo que el recálculo suma.
            $table->index(['tenant_id', 'pos_account_id'], 'pos_discounts_account_index');

            // El reporte de descuentos por persona y por fecha: la mitigación del robo hormiga que §9 pide. Va por
            // AUTORIZADOR porque es la firma que importa — quien aplicó ya está en la cuenta.
            $table->index(['tenant_id', 'authorized_by_membership_id', 'created_at'], 'pos_discounts_authorizer_index');
        });

        // Un descuento de cero no es un descuento; uno negativo sería un cargo encubierto.
        DB::statement('ALTER TABLE `pos_discounts` ADD CONSTRAINT `pos_discounts_amount_chk` CHECK (`resulting_amount` > 0)');

        // Un porcentaje va de 0 a 100 exclusivo–inclusive. Sin esto, un «120 %» daría un descuento mayor que la cuenta y
        // un total negativo — el negocio pagándole al cliente.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_discounts`
            ADD CONSTRAINT `pos_discounts_percentage_chk` CHECK (
                `kind` <> 'percentage' OR (`value` > 0 AND `value` <= 100)
            )
        SQL);

        // El motivo, de verdad obligatorio.
        DB::statement("ALTER TABLE `pos_discounts` ADD CONSTRAINT `pos_discounts_reason_chk` CHECK (CHAR_LENGTH(TRIM(`reason`)) >= 3)");

        // Una CORTESÍA es siempre de un item: es «este plato va por la casa» (§6.3). Una cortesía de cuenta entera sería
        // regalar la comida de una mesa completa, y eso es un descuento del 100 % — que sí existe y deja rastro como tal.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_discounts`
            ADD CONSTRAINT `pos_discounts_courtesy_chk` CHECK (
                `kind` <> 'courtesy' OR `pos_order_item_id` IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_discounts');
    }
};
