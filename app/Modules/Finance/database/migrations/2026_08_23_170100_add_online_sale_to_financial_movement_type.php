<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Añade `online_sale` al catálogo de tipos del diario (Iteración 8, ADR-010).
 *
 * Una venta de la tienda en línea es una venta que no pasó por una caja: cobra por pasarela, sin sesión ni turno.
 * Reutilizar `sale` obligaría a relajar el invariante de §6.3 —«toda venta pertenece a una sesión»—, el candado que
 * atrapa una venta de mostrador sin turno; un tipo propio lo deja intacto y hace del canal una consulta por tipo. El
 * enum de PHP ya lo tiene; esto lo añade a la columna, que es un `ENUM` de MySQL con la lista cerrada.
 *
 * Se coloca junto a `sale`, con la que comparte naturaleza (suma como venta), para que la definición se lea agrupada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','online_sale','payment','change','tip','tip_settlement','discount','courtesy','promotion',
                'expense','withdrawal','deposit','credit_granted','credit_repayment','opening_float',
                'count_difference','reversal'
            ) NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','payment','change','tip','tip_settlement','discount','courtesy','promotion',
                'expense','withdrawal','deposit','credit_granted','credit_repayment','opening_float',
                'count_difference','reversal'
            ) NOT NULL
        SQL);
    }
};
