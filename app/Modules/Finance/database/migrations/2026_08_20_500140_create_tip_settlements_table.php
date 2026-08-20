<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tip_settlements` — la propina que se le entregó a alguien. INMUTABLE.
 *
 * ## Por qué liquidar propinas es NECESARIO y no un extra (D235)
 *
 * Esta iteración crea las propinas: el cliente deja cien pesos y entran a la caja con el resto del cobro. Sin
 * liquidarlas, ese dinero se acumula en el cajón **sin salida registrada** — y cuando el cajero se las entrega al mesero
 * al cerrar, el arqueo da corto por una cantidad que ningún movimiento explica.
 *
 * Es la cuarta de las cosas que D235 identificó como necesarias, y la que menos se ve venir hasta que el primer corte
 * real no cuadra.
 *
 * ## Liquidación SIMPLE (D39)
 *
 * Un movimiento tipado que **afecta cajón**, porque la propina se entrega en efectivo de la caja. No hay reparto por
 * porcentajes, ni pool entre meseros, ni retención: eso es una política laboral y varía por negocio. Lo que entra es el
 * hecho — a quién se le pagó cuánto y quién se lo pagó.
 *
 * ## El monto disponible NO se almacena
 *
 * Se calcula: lo que le corresponde menos lo ya liquidado. Es la misma decisión que el corte (§6.5, ADR-004) — una cifra
 * almacenada como verdad paralela se desvía y nadie sabe cuál era la buena.
 *
 * Y se calcula **del diario**, no de los pagos: los asientos de tipo `tip` llevan como actor a quien se le atribuye la
 * propina, que se decidió así en el paso 10 justamente para esto. `Finance` no puede leer `pos_payments` sin cerrar un
 * ciclo con `Pos`, y no le hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tip_settlements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // El turno del que sale el efectivo. NOT NULL: la propina se paga del cajón, y el arqueo tiene que saber
            // que salió.
            $table->foreignId('pos_session_id')
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            // A quién se le paga.
            $table->foreignId('membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            // Quién se lo entregó. Dos columnas distintas porque son dos personas: el cajero le paga al mesero, y
            // «quién entregó» es la mitad de la evidencia.
            $table->foreignId('paid_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'tip_settlements_ulid_unique');

            // «Cuánto le he liquidado a esta persona», que es la resta del disponible.
            $table->index(['tenant_id', 'membership_id', 'created_at'], 'tip_settlements_membership_index');

            // «Qué salió de esta caja por propinas», que es el arqueo del turno.
            $table->index(['tenant_id', 'pos_session_id'], 'tip_settlements_session_index');
        });

        DB::statement('ALTER TABLE `tip_settlements` ADD CONSTRAINT `tip_settlements_amount_chk` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tip_settlements');
    }
};
