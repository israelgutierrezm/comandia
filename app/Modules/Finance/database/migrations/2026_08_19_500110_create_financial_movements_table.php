<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `financial_movements` — el diario financiero (ADR-004, §6.5).
 *
 * ## Append-only, y eso no es una preferencia
 *
 * Sin UPDATE ni DELETE. Una corrección es un movimiento de **reversa enlazado** a su original, nunca una edición. Es lo
 * que hace que el diario sea evidencia: un asiento que se puede cambiar no prueba nada, y el día que alguien audite un
 * faltante de caja la pregunta no será «cuánto dice el sistema» sino «qué pasó y en qué orden».
 *
 * ## Los cortes se CALCULAN de aquí, nunca se almacenan
 *
 * §6.5 lo prohíbe expresamente: un corte guardado es una verdad paralela que se desvía en cuanto llega un movimiento
 * más. Así que no hay tabla de cortes — hay una consulta agrupada por método de pago, y la diferencia entre lo esperado
 * y lo declarado se asienta **aquí mismo** como un movimiento de tipo `count_difference`.
 *
 * ## `affects_cash_drawer` se COPIA al asentar
 *
 * Podría leerse del método de pago cuando haga falta, y sería un error: si mañana alguien cambia la configuración del
 * método, los cortes de ayer cambiarían con ella. El asiento tiene que decir lo que era verdad **cuando ocurrió el
 * hecho**, que es la misma razón por la que el POS congela el precio en la línea de venta.
 *
 * ## El documento origen va por ULID
 *
 * `source_type` + `source_ulid`, y no la llave interna, por la lección de D151: la llave sólo significa algo mientras
 * la fila exista, y no se puede exponer por la API (§7). Con el ULID, un asiento leído desde la API dice a qué documento
 * pertenece y ese dato sigue siendo cierto si el documento se archiva.
 *
 * ## Y `pos_session_id` NO tiene clave foránea todavía
 *
 * `pos_sessions` se crea en el paso 6 de esta iteración: el diario va antes porque sin él no hay corte, y la sesión va
 * después porque necesita métodos de pago para sus declaraciones. La columna nace sin constraint y la migración del
 * paso 6 la añade. Queda anotado en los dos sitios para que no se quede así — una FK que falta es una fuga de
 * integridad que nadie ve hasta que hay datos huérfanos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_movements', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // La sucursal es obligatoria: un movimiento financiero siempre ocurre en un sitio, y los reportes de §6.7
            // se piden por sucursal antes que por cualquier otra cosa.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->enum('type', [
                'sale', 'payment', 'change', 'tip', 'tip_settlement', 'discount', 'courtesy',
                'expense', 'withdrawal', 'deposit', 'credit_granted', 'credit_repayment',
                'opening_float', 'count_difference', 'reversal',
            ]);

            // Sin FK hasta el paso 6 — ver la nota de arriba.
            $table->unsignedBigInteger('pos_session_id')->nullable();

            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->boolean('affects_cash_drawer')->default(false);

            // Con signo: el sentido lo lleva el monto. DECIMAL(12,2) como todo el dinero del sistema (§7).
            $table->decimal('amount', 12, 2);

            // El documento que lo originó. `VARCHAR(120)` para el nombre de clase completo y `CHAR(26)` para su ULID.
            $table->string('source_type', 120);
            $table->char('source_ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('actor_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->foreignId('reverses_movement_id')
                ->nullable()
                ->constrained('financial_movements')
                ->restrictOnDelete();

            // Cuándo ocurrió el HECHO, que no siempre es cuándo se registró: un gasto se captura al día siguiente con
            // su fecha real. El corte agrupa por esto y no por `created_at`.
            $table->timestamp('occurred_at');

            // Sin `updated_at`: una tabla append-only no tiene fecha de modificación.
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'financial_movements_ulid_unique');

            // IDEMPOTENCIA, y es la razón por la que re-despachar un evento es seguro.
            //
            // Un documento produce a lo sumo UN movimiento de cada tipo: una cuenta pagada asienta una venta, sus pagos
            // y su propina, y volver a despachar el evento no duplica ninguno. Es la regla de jobs idempotentes de
            // CLAUDE.md aplicada al dinero, y lo que permite REPARAR un fallo de oyente re-despachando en lugar de
            // corrigiendo a mano.
            //
            // Nota de diseño: los pagos de una cuenta son varios, así que su asiento NO va como un movimiento `payment`
            // por cuenta sino uno por línea de pago — cada línea es su propio documento origen. Sin eso, esta llave
            // única impediría el segundo pago de una cuenta partida entre efectivo y tarjeta.
            $table->unique(
                ['tenant_id', 'source_type', 'source_ulid', 'type'],
                'financial_movements_idempotency_unique'
            );

            // El corte: agrupar por tipo dentro de una sesión.
            $table->index(['tenant_id', 'pos_session_id', 'type'], 'financial_movements_session_type_index');

            // Los reportes de la Iteración 8: por sucursal y periodo.
            $table->index(['tenant_id', 'branch_id', 'occurred_at'], 'financial_movements_branch_date_index');

            // «¿Qué asentó este documento?», que es la consulta de auditoría y la que responde si una confirmación
            // quedó a medias.
            $table->index(['tenant_id', 'source_type', 'source_ulid'], 'financial_movements_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_movements');
    }
};
