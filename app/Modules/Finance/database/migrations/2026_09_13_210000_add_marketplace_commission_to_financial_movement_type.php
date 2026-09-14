<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amplía el ENUM `financial_movements.type` con `marketplace_commission` (ADR-015, enmienda a ADR-004/ADR-010).
 *
 * La comisión que un marketplace retiene de un pedido es un movimiento tipado propio (netea la venta en
 * línea, no la reemplaza). Como es un ENUM de MySQL, hay que ampliarlo con `MODIFY COLUMN`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','online_sale','marketplace_commission','payment','change','tip','tip_settlement',
                'discount','courtesy','promotion','expense','withdrawal','deposit','credit_granted',
                'credit_repayment','opening_float','count_difference','reversal'
            ) NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `financial_movements` MODIFY COLUMN `type` ENUM(
                'sale','online_sale','payment','change','tip','tip_settlement','discount','courtesy','promotion',
                'expense','withdrawal','deposit','credit_granted','credit_repayment','opening_float',
                'count_difference','reversal'
            ) NOT NULL
        SQL);
    }
};
