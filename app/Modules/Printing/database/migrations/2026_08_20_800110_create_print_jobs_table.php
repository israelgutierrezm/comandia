<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `print_jobs` — lo que hay que sacar por una impresora.
 *
 * ## El `payload` es JSON, y es la EXCEPCIÓN AUTORIZADA
 *
 * CLAUDE.md prohíbe JSON en datos de dominio y nombra dos excepciones: la bitácora de auditoría y los payloads de
 * impresión. Ésta es la segunda, y conviene decir por qué es legítima y no una comodidad.
 *
 * Un payload de impresión **no se consulta**: no se filtra por él, no se agrega, no se une con nada. Se escribe una vez
 * y se lee entero, por un proceso que sólo quiere convertirlo en papel. Y su forma depende del tipo de documento —una
 * comanda, un ticket final y una apertura de cajón no comparten ni una columna—, así que normalizarlo daría tres tablas
 * de detalle que nadie consultaría nunca por separado.
 *
 * ## Y se CONGELA al crear el trabajo
 *
 * El payload se arma en el momento y se guarda. Reimprimir vuelve a mandar **el mismo papel**, aunque el ticket haya
 * cambiado, aunque el artículo se haya renombrado, aunque el negocio haya cambiado de dirección. Es la misma disciplina
 * del precio congelado en la línea, y aquí importa igual: una reimpresión que dice algo distinto del original es lo
 * único que una reimpresión no puede hacer.
 *
 * ## `drawer_open` es un trabajo de impresión, y no es un truco
 *
 * El cajón de dinero no tiene cable propio: se abre mandándole una secuencia a la impresora de tickets, que tiene un
 * conector para eso. Así que «abrir el cajón» **es** mandar algo a imprimir, y modelarlo como otra cosa obligaría a un
 * segundo canal hacia el mismo agente y la misma impresora. De ahí que `pos_ticket_id` sea nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->enum('kind', ['ticket', 'drawer_open']);

            $table->foreignId('pos_ticket_id')
                ->nullable()
                ->constrained('pos_tickets')
                ->restrictOnDelete();

            $table->foreignId('printer_id')
                ->constrained('printers')
                ->restrictOnDelete();

            $table->enum('status', ['pending', 'claimed', 'printed', 'failed', 'cancelled'])->default('pending');

            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);

            // El NOMBRE del agente y no su FK, a propósito: es una traza de quién lo tomó, y tiene que sobrevivir a que
            // el agente se dé de baja. Un `RESTRICT` aquí impediría retirar una computadora vieja de la cocina mientras
            // quedara un trabajo suyo en la historia.
            $table->string('claimed_by_agent', 80)->nullable();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->string('last_error', 300)->nullable();

            $table->timestamps();

            $table->unique('ulid', 'print_jobs_ulid_unique');

            // La consulta del agente es «dame lo pendiente de esta sucursal», y es la que corre cada pocos segundos por
            // cada agente. El `id` al final hace el orden determinista: sin él, dos agentes de la misma sucursal podrían
            // recibir el mismo lote en distinto orden y el papel saldría desordenado.
            $table->index(['tenant_id', 'branch_id', 'status', 'id'], 'print_jobs_pending_index');

            // «Qué se mandó a imprimir de este ticket», que es la pregunta cuando la cocina dice que no le llegó nada.
            $table->index(['tenant_id', 'pos_ticket_id'], 'print_jobs_ticket_index');
        });

        // Un trabajo de cajón no lleva ticket, y uno de ticket sí. Sin esto, un `drawer_open` con ticket sería un papel
        // que se imprimiría al abrir el cajón —comida duplicada si el ticket era una comanda— y un `ticket` sin ticket
        // sería un trabajo sin nada que imprimir.
        DB::statement(<<<'SQL'
            ALTER TABLE `print_jobs`
            ADD CONSTRAINT `print_jobs_kind_shape_chk` CHECK (
                (`kind` = 'ticket' AND `pos_ticket_id` IS NOT NULL) OR
                (`kind` = 'drawer_open' AND `pos_ticket_id` IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
