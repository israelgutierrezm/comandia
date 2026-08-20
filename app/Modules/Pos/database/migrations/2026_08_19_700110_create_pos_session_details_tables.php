<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_session_declarations` y `pos_session_withdrawals` — lo que el cajero declara y lo que saca.
 *
 * ## Las declaraciones NO llevan el esperado ni la diferencia
 *
 * Los dos se **calculan** del diario al vuelo (§6.5, ADR-004). Guardarlos sería la verdad paralela que ADR-004 prohíbe,
 * y además quedarían desactualizados en cuanto se asentara un movimiento más — que en una caja abierta pasa cada minuto.
 *
 * Una declaración por método y por momento: el cajero cuenta el efectivo, mira el corte de la terminal bancaria y
 * declara los dos por separado. Un único total mezclado no serviría para saber **dónde** falta dinero, que es la única
 * pregunta útil cuando un corte no cuadra.
 *
 * ## Los retiros son APPEND-ONLY
 *
 * Un retiro es dinero que salió del cajón: si se pudiera editar, el arqueo dejaría de ser evidencia. La corrección es un
 * retiro en contra —o un asiento de reversa en el diario—, nunca un `UPDATE`. Entra en la lista de inmutables de §7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_session_declarations', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // CASCADE: una declaración no existe fuera de su sesión, y sin la sesión no significa nada.
            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->cascadeOnDelete();

            $table->enum('moment', ['precount', 'close']);

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->decimal('declared_amount', 12, 2);

            // Quién declaró: puede no ser quien abrió el turno. En un cambio de turno cuenta el que entra, y saber
            // quién dijo qué es la mitad del valor de un arqueo.
            $table->foreignId('declared_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('declared_at');

            $table->timestamps();

            $table->unique('ulid', 'pos_session_declarations_ulid_unique');

            // Una declaración por (sesión, momento, método). Volver a declarar el mismo método en el mismo momento es
            // corregir un dedazo, y eso se hace actualizando la declaración: todavía no es evidencia de nada —el arqueo
            // no ha ocurrido— así que aquí sí se puede editar, al contrario que el retiro.
            $table->unique(
                ['pos_session_id', 'moment', 'payment_method_id'],
                'pos_session_declarations_unique'
            );
        });

        Schema::create('pos_session_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT y no CASCADE, a diferencia de las declaraciones: un retiro es dinero que salió, y si se borrara
            // con la sesión el diario tendría un asiento sin documento que lo respalde.
            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            // Obligatorio, como el motivo de una merma (D27): un retiro sin motivo es dinero que salió del cajón y
            // nadie puede explicar.
            $table->string('reason', 300);

            $table->foreignId('performed_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Quién lo autorizó con su PIN. `null` cuando no hizo falta autorización — y NO se rellena con quien lo
            // hizo: «no hacía falta» y «se autorizó a sí mismo» son cosas distintas (D172).
            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Sin `updated_at`: append-only.
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'pos_session_withdrawals_ulid_unique');

            $table->index(['tenant_id', 'pos_session_id'], 'pos_session_withdrawals_session_index');
        });

        DB::statement(
            'ALTER TABLE pos_session_declarations ADD CONSTRAINT '
            .'pos_session_declarations_amount_not_negative CHECK (declared_amount >= 0)'
        );

        // Un retiro de cero o negativo no es un retiro. En positivo siempre: el signo lo pone el diario al asentar, que
        // es donde el sentido del dinero se decide (§6.5).
        DB::statement(
            'ALTER TABLE pos_session_withdrawals ADD CONSTRAINT '
            .'pos_session_withdrawals_amount_positive CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_withdrawals');
        Schema::dropIfExists('pos_session_declarations');
    }
};
