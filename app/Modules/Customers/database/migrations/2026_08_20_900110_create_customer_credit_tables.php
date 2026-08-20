<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `customer_credits` y `customer_credit_movements` — el fiado con nombre.
 *
 * ## Por qué el crédito es NECESARIO y no un extra (D235)
 *
 * §6.3 prohíbe «la cuenta que nunca se cierra», y el crédito **es** el mecanismo para el fiado. Sin él, un negocio que
 * fía deja cuentas abiertas para siempre — justo lo prohibido — y el corte de cada noche arrastra consumos de hace
 * semanas. Con crédito, el fiado deja de ser una cuenta abierta y pasa a ser un saldo con nombre.
 *
 * ## `balance` es PROYECCIÓN, no verdad
 *
 * Se reconstruye de los movimientos, igual que `article_stocks` frente al kardex y por la misma razón: un saldo
 * almacenado como verdad única se desvía —una escritura que falla a medias, un ajuste manual— y nadie puede
 * reconstruirlo para saber cuál era el bueno.
 *
 * Con los movimientos, el saldo siempre se puede volver a calcular sumando. La proyección existe para no sumar en cada
 * consulta, no para ser la fuente.
 *
 * ## `balance_after` en cada movimiento, calculado bajo lock
 *
 * Es el patrón del kardex, deliberadamente. Permite contestar «¿cuánto debía el 3 de marzo?» sin sumar la historia
 * entera, y permite detectar una desviación: si el `balance_after` del último movimiento no coincide con la proyección,
 * algo escribió por fuera.
 *
 * ## Inmutable
 *
 * Un movimiento de crédito es dinero que alguien debe o pagó. Corregirlo es un `adjustment` en contra, nunca un UPDATE:
 * el estado de cuenta que el cliente ya vio no puede cambiar de contenido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // Uno por cliente. El único es lo que impide que dos filas de crédito del mismo cliente lleven saldos
            // distintos, que es el defecto que ningún reporte detectaría.
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            $table->decimal('credit_limit', 12, 2)->default(0);

            // La PROYECCIÓN. Positivo = el cliente debe.
            $table->decimal('balance', 12, 2)->default(0);

            // Se puede tener límite y estar suspendido: un cliente que se atrasó no pierde su límite, pierde el permiso
            // de usarlo. Volver a habilitarlo no exige recapturar nada.
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->unique('ulid', 'customer_credits_ulid_unique');
            $table->unique(['tenant_id', 'customer_id'], 'customer_credits_customer_unique');
        });

        DB::statement('ALTER TABLE `customer_credits` ADD CONSTRAINT `customer_credits_limit_chk` CHECK (`credit_limit` >= 0)');

        Schema::create('customer_credit_movements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->enum('type', ['charge', 'repayment', 'adjustment']);

            // CON SIGNO: un cargo suma a lo que debe, un abono resta. Guardar el signo en el monto y no deducirlo del
            // tipo permite que un `adjustment` vaya en cualquier dirección sin inventar dos tipos.
            $table->decimal('amount', 12, 2);

            $table->decimal('balance_after', 12, 2);

            // El documento que lo causó: la cuenta que se fió, el abono. Por ULID y no por llave interna (D151).
            $table->string('source_type', 120)->nullable();
            $table->char('source_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();

            // El turno, para los abonos: entran a la caja como efectivo y el arqueo tiene que conocerlos.
            $table->foreignId('pos_session_id')
                ->nullable()
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'customer_credit_movements_ulid_unique');

            // El estado de cuenta de un cliente, que es la consulta que se hace con él delante.
            $table->index(['tenant_id', 'customer_id', 'created_at'], 'customer_credit_movements_statement_index');

            // Idempotencia: re-despachar el evento de una cuenta fiada no duplica su cargo. Es la misma llave que el
            // diario financiero, por la misma razón.
            $table->unique(['tenant_id', 'source_type', 'source_ulid', 'type'], 'customer_credit_movements_source_unique');
        });

        DB::statement('ALTER TABLE `customer_credit_movements` ADD CONSTRAINT `customer_credit_movements_amount_chk` CHECK (`amount` <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_movements');
        Schema::dropIfExists('customer_credits');
    }
};
