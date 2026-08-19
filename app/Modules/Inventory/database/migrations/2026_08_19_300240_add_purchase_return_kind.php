<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega `purchase_return` al enum de tipos de movimiento (paso 9).
 *
 * Es el mecanismo con el que se deshace una recepción confirmada: §3.2 dice que una recepción confirmada **no se
 * edita**, se reversa. Y una reversa tiene que salir del almacén, mientras que `purchase_receipt` tiene dirección fija
 * de entrada — así que hacía falta un tipo propio.
 *
 * No se usó `manual_adjustment`, que admite las dos direcciones, porque la razón de la salida **se conoce**: la
 * mercancía volvió al proveedor. Un ajuste significa «salió algo y nadie sabe por qué» (D157), y usarlo aquí volvería
 * mentiroso el reporte que distingue el consumo interno de los descuadres sin explicar.
 *
 * Mismo procedimiento que la migración de los tipos manuales (paso 2): la lista del enum se escribe desde el enum de
 * PHP para que las dos definiciones no puedan divergir, y el CHECK de dirección se recrea porque nombra los tipos.
 *
 * Alterar el enum de una tabla inmutable es legítimo: inmutable se refiere a las **filas**, no al esquema (D151).
 */
return new class extends Migration
{
    public function up(): void
    {
        $valores = implode(',', array_map(
            fn (string $v): string => "'".$v."'",
            StockMovementKind::values(),
        ));

        DB::statement("ALTER TABLE `stock_movements` MODIFY `kind` ENUM({$valores}) NOT NULL");

        DB::statement('ALTER TABLE `stock_movements` DROP CONSTRAINT `chk_stock_movements_direction_matches_kind`');

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `chk_stock_movements_direction_matches_kind` CHECK (
                (`kind` IN ('purchase_receipt','transfer_in','production_in','sale_return','initial_load','manual_entry')
                    AND `direction` = 'in')
                OR (`kind` IN ('purchase_return','transfer_out','production_out','sale_consumption','waste','manual_exit')
                    AND `direction` = 'out')
                OR `kind` IN ('count_adjustment','manual_adjustment')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `stock_movements` DROP CONSTRAINT `chk_stock_movements_direction_matches_kind`');

        DB::statement("DELETE FROM `stock_movements` WHERE `kind` = 'purchase_return'");

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            MODIFY `kind` ENUM(
                'purchase_receipt','transfer_out','transfer_in','production_in','production_out',
                'sale_consumption','sale_return','waste','count_adjustment','manual_adjustment',
                'manual_entry','manual_exit','initial_load'
            ) NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `chk_stock_movements_direction_matches_kind` CHECK (
                (`kind` IN ('purchase_receipt','transfer_in','production_in','sale_return','initial_load','manual_entry')
                    AND `direction` = 'in')
                OR (`kind` IN ('transfer_out','production_out','sale_consumption','waste','manual_exit')
                    AND `direction` = 'out')
                OR `kind` IN ('count_adjustment','manual_adjustment')
            )
        SQL);
    }
};
