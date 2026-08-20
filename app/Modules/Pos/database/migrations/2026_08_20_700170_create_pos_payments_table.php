<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_payments` — cómo se pagó cada cuenta. INMUTABLE.
 *
 * ## Append-only, y por qué el dinero no se edita
 *
 * §7 lista los pagos entre los inmutables. Corregir un pago es registrar su **reversa**: una línea nueva con monto
 * negativo que apunta a la original. Un `UPDATE` sobre un pago cambiaría la historia sin cambiar el dinero, y el corte
 * de anoche —que ya se imprimió y alguien firmó— diría otra cosa al volver a calcularlo.
 *
 * Es la misma disciplina del kardex y del diario financiero, aplicada donde más importa.
 *
 * ## MULTI-LÍNEA, porque una cuenta se paga con varias cosas
 *
 * Mil pesos con quinientos en efectivo y quinientos con tarjeta son **dos filas**. Modelarlo como columnas en la cuenta
 * —`cash_amount`, `card_amount`— obligaría a una columna por método, y los métodos los define el negocio.
 *
 * ## La propina va POR LÍNEA de pago, con nombre
 *
 * `tip_amount` y `tip_membership_id` en cada pago, congelados al cobrar (D233). No en la cuenta, por dos razones: la
 * propina de la tarjeta y la del efectivo pueden ser distintas y de personas distintas —el cajero cobra la cuenta cuya
 * mesera la atendió— y sobre todo porque congelarla aquí hace que **una operación posterior no reescriba propinas ya
 * pagadas**. Si a las 22:00 se juntan dos cuentas, las propinas cobradas a las 21:00 siguen siendo de quien las ganó.
 *
 * ## `tendered_amount` y `change_amount` se GUARDAN, no se calculan al vuelo
 *
 * Lo que el cliente entregó y lo que se le devolvió son hechos, no cuentas. Recalcular el cambio después de un cambio de
 * precio o de un descuento daría otro número, y el cajón ya se abrió con la cifra de aquel momento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('pos_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            // NOT NULL: todo pago pertenece a una sesión de caja (§6.3). Es lo que hace que el corte pueda existir —un
            // pago huérfano sería dinero que entró en ningún turno.
            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            // Sólo el efectivo: lo que el cliente puso sobre la barra.
            $table->decimal('tendered_amount', 12, 2)->nullable();

            $table->decimal('change_amount', 12, 2)->default(0);

            $table->decimal('tip_amount', 12, 2)->default(0);

            $table->foreignId('tip_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // La autorización de la tarjeta o el folio de la transferencia. Sin esto, una transferencia no se concilia
            // con el estado de cuenta del banco y el dinero queda sin comprobar.
            $table->string('reference', 60)->nullable();

            $table->foreignId('charged_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // La reversa enlaza a su original. Se guarda el enlace y no sólo el signo porque «¿qué se corrigió?» es la
            // pregunta de cualquier revisión, y sin él habría que adivinarlo por monto y hora.
            $table->foreignId('reverses_payment_id')
                ->nullable()
                ->constrained('pos_payments')
                ->restrictOnDelete();

            $table->timestamp('occurred_at');

            // Sin `updated_at`: la tabla es inmutable y el trait quita los timestamps de Eloquent.
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'pos_payments_ulid_unique');

            // El corte agrupa exactamente por sesión y método: es la consulta que se corre al cerrar cada turno.
            $table->index(['tenant_id', 'pos_session_id', 'payment_method_id'], 'pos_payments_session_method_index');

            // «¿Cómo se pagó esta cuenta?», que es lo que pinta el ticket final.
            $table->index(['tenant_id', 'pos_account_id'], 'pos_payments_account_index');

            // La liquidación de propinas del paso 18: cuánto le toca a cada quien en un rango de fechas.
            $table->index(['tenant_id', 'tip_membership_id', 'occurred_at'], 'pos_payments_tip_index');
        });

        // Un pago de cero no es un pago. Una reversa es negativa, así que no se puede exigir `> 0`.
        DB::statement('ALTER TABLE `pos_payments` ADD CONSTRAINT `pos_payments_amount_chk` CHECK (`amount` <> 0)');

        // La propina y el cambio nunca son negativos, ni siquiera en una reversa: lo que se revierte es el pago entero,
        // con su propina y su cambio en la línea nueva, y una propina negativa sería quitarle dinero a alguien por una
        // vía que nadie revisa.
        DB::statement('ALTER TABLE `pos_payments` ADD CONSTRAINT `pos_payments_tip_chk` CHECK (`tip_amount` >= 0)');
        DB::statement('ALTER TABLE `pos_payments` ADD CONSTRAINT `pos_payments_change_chk` CHECK (`change_amount` >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
