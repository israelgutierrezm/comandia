<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_tickets` y `pos_ticket_items` — lo que se imprimió, y de lo que hay que poder decir cuándo y quién.
 *
 * ## Una comanda, un ticket de cierre y un ticket final son el mismo género
 *
 * Los tres son papel que salió de una impresora sobre una cuenta. Separarlos en tres tablas repetiría la misma columna
 * de reimpresiones tres veces y obligaría a tres consultas para contestar «qué se imprimió de esta cuenta», que es la
 * pregunta que se hace cuando algo no cuadra. `kind` los distingue.
 *
 * ## Sólo el ticket final folia (§3.6 del diseño)
 *
 * Una comanda es un papel de cocina, no un documento con valor: foliarla serializaría la captura por sucursal justo en
 * hora pico, que es cuando el POS no se puede detener. El ticket de cierre tampoco folia — es una previsualización que
 * se reimprime a voluntad. El **ticket final** sí, porque será el folio facturable (ADR-005).
 *
 * De ahí que `series` y `folio` sean nullable con un CHECK que los ata a `final_receipt`: nullable sin la regla dejaría
 * la puerta abierta a foliar una comanda «por si acaso», que es como una columna opcional se convierte en obligatoria
 * sin que nadie lo decida.
 *
 * ## `reprint_count` y no una tabla de reimpresiones
 *
 * Reimprimir es auditado —la bitácora guarda quién y cuándo—, así que el contador de aquí es para la operación: «este
 * ticket ya salió tres veces» es lo que el cajero necesita ver, y una tabla aparte lo volvería un `COUNT` en la pantalla
 * más caliente del sistema. El detalle de quién reimprimió vive donde vive todo lo demás: en `audit_logs`.
 *
 * ## `pos_ticket_items` con cantidad, porque se comanda de a partes
 *
 * Tres tacos en la comanda de las 20:14 y dos más en la de las 20:31, de la misma línea. Sin `quantity` en el detalle,
 * la comanda tendría que decir «5 tacos» cada vez y la cocina prepararía ocho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tickets', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->enum('kind', [
                'command',
                'command_cancellation',
                'bill_preview',
                'final_receipt',
            ]);

            $table->foreignId('pos_account_id')
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            // Sólo las comandas cuelgan de una orden: un ticket de cierre es de la cuenta entera.
            $table->foreignId('pos_order_id')
                ->nullable()
                ->constrained('pos_orders')
                ->restrictOnDelete();

            $table->foreignId('preparation_area_id')
                ->nullable()
                ->constrained('preparation_areas')
                ->restrictOnDelete();

            $table->char('series', 8)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('folio')->nullable();

            $table->foreignId('issued_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('issued_at');

            $table->unsignedSmallInteger('reprint_count')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'pos_tickets_ulid_unique');

            // El folio del ticket final no se repite. Va con la sucursal porque la foliación es por (tenant, sucursal,
            // tipo, serie) — dos sucursales llevan su propia numeración.
            $table->unique(['tenant_id', 'branch_id', 'series', 'folio'], 'pos_tickets_folio_unique');

            // «Qué se imprimió de esta cuenta», que es la consulta de la pantalla de la cuenta y de cualquier revisión
            // de por qué la cocina no recibió algo.
            $table->index(['tenant_id', 'pos_account_id', 'kind'], 'pos_tickets_account_kind_index');

            // «Qué se comandó a esta área hoy»: es como se reconstruye la carga de la cocina y como el agente de
            // impresión encuentra lo pendiente de su área.
            $table->index(['tenant_id', 'preparation_area_id', 'issued_at'], 'pos_tickets_area_issued_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `pos_tickets`
            ADD CONSTRAINT `pos_tickets_folio_kind_chk` CHECK (
                (`kind` = 'final_receipt' AND `series` IS NOT NULL AND `folio` IS NOT NULL) OR
                (`kind` <> 'final_receipt' AND `series` IS NULL AND `folio` IS NULL)
            )
        SQL);

        // Una comanda va a un área y sale de una orden; los tickets de la cuenta no tienen ni una ni otra. La regla
        // vive en la base porque un ticket de cierre con área sería un papel que la cocina esperaría y nunca llegaría a
        // ningún sitio.
        DB::statement(<<<'SQL'
            ALTER TABLE `pos_tickets`
            ADD CONSTRAINT `pos_tickets_command_shape_chk` CHECK (
                (`kind` IN ('command', 'command_cancellation') AND `pos_order_id` IS NOT NULL) OR
                (`kind` IN ('bill_preview', 'final_receipt') AND `pos_order_id` IS NULL AND `preparation_area_id` IS NULL)
            )
        SQL);

        Schema::create('pos_ticket_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('pos_ticket_id')
                ->constrained('pos_tickets')
                ->cascadeOnDelete();

            $table->foreignId('pos_order_item_id')
                ->constrained('pos_order_items')
                ->restrictOnDelete();

            $table->decimal('quantity', 12, 4);

            $table->timestamps();

            // La misma línea puede aparecer en dos comandas —tres tacos ahora y dos después— así que el único no es
            // por (ticket, item) a secas… y sí lo es: dentro de UN ticket una línea aparece una sola vez, con su
            // cantidad sumada. Dos renglones de lo mismo en el mismo papel es un error de la cocina esperando.
            $table->unique(['pos_ticket_id', 'pos_order_item_id'], 'pos_ticket_items_unique');

            // «En qué comandas salió esta línea», que es la pregunta al cancelar un item ya comandado: hay que saber a
            // qué áreas avisar.
            $table->index(['tenant_id', 'pos_order_item_id'], 'pos_ticket_items_item_index');
        });

        DB::statement('ALTER TABLE `pos_ticket_items` ADD CONSTRAINT `pos_ticket_items_qty_chk` CHECK (`quantity` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ticket_items');
        Schema::dropIfExists('pos_tickets');
    }
};
