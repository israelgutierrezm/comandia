<?php

declare(strict_types=1);

use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Separa la ENTRADA y la SALIDA manuales del AJUSTE (paso 2 de la iteración).
 *
 * ## Por qué el enum del paso 1 se queda corto
 *
 * El catálogo cerrado de permisos distingue tres cosas —`inventory.entries.create`,
 * `inventory.exits.create`, `inventory.adjustments.create`— y el enum sólo tenía `manual_adjustment` para
 * las tres. Con eso, los tres permisos habrían acabado apuntando al mismo tipo de movimiento, y la
 * distinción del catálogo se habría quedado en la puerta sin llegar al dato.
 *
 * Y no es una distinción burocrática: son tres cosas que un negocio hace por razones distintas.
 *
 *   - **Entrada manual:** entró algo que no fue compra — muestras del proveedor, una devolución.
 *   - **Salida manual:** salió algo que no fue venta ni merma — consumo interno, se lo llevó el dueño.
 *   - **Ajuste:** el sistema dice 10 y hay 8, y **no se sabe por qué**. Es la confesión de un descuadre.
 *
 * Colapsarlas dejaba sin respuesta la pregunta que hace útil un kardex: «¿cuánto salió por consumo interno
 * y cuánto por diferencias que nadie explicó?». Con un solo tipo, las dos cifras son la misma.
 *
 * El ajuste se queda para lo que de verdad es un ajuste, y por eso —a diferencia de los otros dos— exigirá
 * nota escrita: un descuadre sin explicación es lo que vuelve un inventario poco creíble.
 *
 * ## Sobre alterar el enum de una tabla inmutable
 *
 * Inmutable se refiere a las **filas** y no al esquema (D151): agregar valores a un enum no modifica ningún
 * movimiento registrado. El CHECK de dirección se recrea porque menciona los tipos por nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El enum, con los dos valores nuevos. Se escribe la lista completa desde el enum de PHP para que
        // las dos definiciones no puedan divergir.
        $valores = implode(',', array_map(
            fn (string $v): string => "'".$v."'",
            StockMovementKind::values(),
        ));

        DB::statement("ALTER TABLE `stock_movements` MODIFY `kind` ENUM({$valores}) NOT NULL");

        // El CHECK nombra los tipos, así que hay que recrearlo. `manual_entry` sólo entra y `manual_exit`
        // sólo sale; el ajuste sigue admitiendo las dos direcciones, porque ahí el signo es la información.
        DB::statement('ALTER TABLE `stock_movements` DROP CONSTRAINT `chk_stock_movements_direction_matches_kind`');

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

    public function down(): void
    {
        DB::statement('ALTER TABLE `stock_movements` DROP CONSTRAINT `chk_stock_movements_direction_matches_kind`');

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            MODIFY `kind` ENUM(
                'purchase_receipt','transfer_out','transfer_in','production_in','production_out',
                'sale_consumption','sale_return','waste','count_adjustment','manual_adjustment','initial_load'
            ) NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `stock_movements`
            ADD CONSTRAINT `chk_stock_movements_direction_matches_kind` CHECK (
                (`kind` IN ('purchase_receipt','transfer_in','production_in','sale_return','initial_load')
                    AND `direction` = 'in')
                OR (`kind` IN ('transfer_out','production_out','sale_consumption','waste')
                    AND `direction` = 'out')
                OR `kind` IN ('count_adjustment','manual_adjustment')
            )
        SQL);
    }
};
