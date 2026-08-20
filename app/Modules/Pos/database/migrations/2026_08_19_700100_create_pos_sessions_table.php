<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_sessions` — la sesión de caja (§6.3).
 *
 * ## Es la primera tabla del POS porque nada se puede cobrar sin ella
 *
 * §6.3 no admite matices: «sin sesión abierta no hay cobro», y «toda venta, pago, retiro y cancelación pertenece a una
 * sesión». Eso es lo que hace que el arqueo signifique algo — un pago que no pertenece a ningún turno no se puede
 * atribuir a nadie, y el corte del día quedaría corto sin que nada avisara.
 *
 * ## Una sola sesión abierta por terminal, garantizada por la BASE
 *
 * Columna generada `open_terminal_key` con índice único: vale el `terminal_id` mientras la sesión no esté cerrada, y
 * `NULL` cuando lo está. MySQL admite tantos nulos como haga falta en un índice único, así que las sesiones cerradas
 * conviven y las abiertas se excluyen entre sí.
 *
 * Es el mismo patrón que el conteo abierto por almacén (D173) y por la misma razón: dos sesiones simultáneas en la misma
 * caja producen dos cortes que se pisan, y el segundo cuadra contra un efectivo que el primero ya contó. Comprobarlo en
 * código dejaría una ventana entre el `SELECT` y el `INSERT` donde caben dos cajeros abriendo a la vez al empezar el
 * turno — que es justo cuando pasa.
 *
 * ## El estado `precounted` está entre abierto y cerrado
 *
 * El precorte ciego (§6.3) es una declaración intermedia: el cajero cuenta sin ver lo esperado y **la caja sigue
 * operando**. No es un tercer estado terminal, es un sello de que el precorte ya ocurrió — por eso la columna generada
 * lo cuenta como abierto (`status <> 'closed'`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Una sesión es de UNA terminal: el arqueo cuenta el efectivo de un cajón físico.
            $table->foreignId('terminal_id')
                ->constrained('terminals')
                ->restrictOnDelete();

            // Foliación por (tenant, sucursal, tipo, serie), sin huecos y bajo lock (§7, D53). El corte se identifica
            // por su número, y un hueco en la secuencia de cortes es lo primero que un auditor pregunta.
            $table->char('series', 4)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('folio');

            $table->enum('status', ['open', 'precounted', 'closed'])->default('open');

            $table->decimal('opening_float', 12, 2);

            $table->foreignId('opened_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('opened_at');

            // El precorte: quién lo hizo y cuándo. Las cantidades declaradas viven en `pos_session_declarations`,
            // porque son una por método de pago.
            $table->foreignId('precounted_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('precounted_at')->nullable();

            $table->foreignId('closed_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('closed_at')->nullable();

            $table->string('closing_notes', 300)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'pos_sessions_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'series', 'folio'], 'pos_sessions_folio_unique');

            // «¿Qué cajas están abiertas ahora?», que es la consulta del gerente y la que la pantalla de caja hace al
            // arrancar para saber si hay turno.
            $table->index(['tenant_id', 'branch_id', 'status'], 'pos_sessions_tenant_branch_status_index');

            // El historial de una caja concreta.
            $table->index(['tenant_id', 'terminal_id', 'opened_at'], 'pos_sessions_terminal_history_index');
        });

        // El fondo de caja no puede ser negativo. Va como CHECK y no sólo como validación porque el fondo lo puede
        // escribir un servicio, un comando o una importación, y un fondo negativo descuadra el arqueo desde el minuto
        // cero sin que nada explique por qué.
        DB::statement('ALTER TABLE pos_sessions ADD CONSTRAINT pos_sessions_opening_float_not_negative CHECK (opening_float >= 0)');

        // Una sola sesión NO CERRADA por terminal.
        DB::statement(
            'ALTER TABLE pos_sessions ADD COLUMN open_terminal_key BIGINT UNSIGNED '
            ."GENERATED ALWAYS AS (IF(status <> 'closed', terminal_id, NULL)) STORED"
        );

        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->unique('open_terminal_key', 'pos_sessions_one_open_per_terminal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
