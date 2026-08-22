<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Añade `promotion` al catálogo de tipos del diario (Iteración 6, D313).
 *
 * Una promoción automática se asienta como el descuento manual —resta de lo vendido— pero con tipo propio, para que el
 * reporte antifraude de §9 distinga lo que un humano autorizó de lo que una regla aplicó sola. El enum de PHP ya lo
 * tiene; esto lo añade a la columna, que es un `ENUM` de MySQL con la lista cerrada.
 *
 * Se coloca junto a `courtesy`, con la que comparte naturaleza (resta de la venta), para que la definición se lea
 * agrupada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','payment','change','tip','tip_settlement','discount','courtesy','promotion',
                'expense','withdrawal','deposit','credit_granted','credit_repayment','opening_float',
                'count_difference','reversal'
            ) NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','payment','change','tip','tip_settlement','discount','courtesy',
                'expense','withdrawal','deposit','credit_granted','credit_repayment','opening_float',
                'count_difference','reversal'
            ) NOT NULL
        SQL);
    }
};
