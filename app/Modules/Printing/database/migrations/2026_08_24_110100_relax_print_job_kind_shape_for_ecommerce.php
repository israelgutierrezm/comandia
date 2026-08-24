<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un trabajo de tipo `ticket` puede no tener ticket del POS (Iteración 8, Tanda D parte 2).
 *
 * La comanda de un pedido de la tienda en línea es un trabajo de impresión `ticket` —imprime un papel de cocina, como la
 * comanda del mostrador— pero **no nace de un `PosTicket`**: la tienda no pasa por uno. El contenido a imprimir vive en el
 * `payload`, no en el ticket enlazado; el `pos_ticket_id` es sólo el vínculo al documento del POS de origen, para reimprimir
 * y rastrear. Así que el invariante original —«un `ticket` sin ticket es un trabajo sin nada que imprimir»— dejó de ser
 * cierto: sí hay qué imprimir, está en el payload.
 *
 * Se relaja la mitad de `ticket` del CHECK y se conserva intacta la de `drawer_open`, que sí importa: un `drawer_open` con
 * ticket enlazado imprimiría comida al abrir el cajón.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `print_jobs` DROP CHECK `print_jobs_kind_shape_chk`');
        DB::statement(<<<'SQL'
            ALTER TABLE `print_jobs`
            ADD CONSTRAINT `print_jobs_kind_shape_chk` CHECK (
                (`kind` = 'ticket') OR
                (`kind` = 'drawer_open' AND `pos_ticket_id` IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `print_jobs` DROP CHECK `print_jobs_kind_shape_chk`');
        DB::statement(<<<'SQL'
            ALTER TABLE `print_jobs`
            ADD CONSTRAINT `print_jobs_kind_shape_chk` CHECK (
                (`kind` = 'ticket' AND `pos_ticket_id` IS NOT NULL) OR
                (`kind` = 'drawer_open' AND `pos_ticket_id` IS NULL)
            )
        SQL);
    }
};
