<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tercer `kind` de almacén: `transit` — donde vive la mercancía que va en camino (paso 6).
 *
 * ## Por qué hace falta un almacén y no basta con las cantidades de la transferencia
 *
 * Al enviar 100 y recibir 95, sin almacén de tránsito el origen baja 100, el destino sube 95 y **ningún
 * movimiento explica los 5 que faltan**. La pérdida quedaría documentada sólo en la transferencia, así que no
 * aparecería en el reporte de mermas — y D168 definió ese reporte como un filtro sobre el kardex. Se rompería la
 * promesa.
 *
 * Con tránsito, cada movimiento dice la verdad literal: origen −100 y tránsito +100 al enviar; tránsito −95 y
 * destino +95 al recibir; y el residuo de 5 que quedó en tránsito se convierte en merma **ahí**, que es donde se
 * perdió. Tránsito queda en cero y el reporte de mermas la ve.
 *
 * La alternativa considerada era recibir los 100 en destino y mermar 5 ahí. Cuadra aritméticamente, y se descartó
 * porque escribe en el kardex del destino una entrada de mercancía que nunca llegó y una merma que no ocurrió ahí.
 * Quien audite ese almacén vería entrar algo que jamás entró, en la tabla que §7 declara evidencia inmutable.
 *
 * ## Uno por negocio, y es un índice único
 *
 * Dos almacenes de tránsito repartirían la mercancía en viaje entre dos saldos y «¿qué traigo en camiones?»
 * tendría dos respuestas. Se impone con el patrón de D93: columna generada que vale 1 sólo cuando el `kind` es
 * `transit` y `NULL` en los demás, con índice único por negocio. MySQL no deduplica `NULL`, así que los almacenes
 * normales no se estorban.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El CHECK se retira ANTES de tocar el enum: mientras existe, prohíbe explícitamente cualquier `kind`
        // que no sea uno de los dos que conoce, así que un `transit` con `branch_id` nulo lo violaría.
        DB::statement('ALTER TABLE `warehouses` DROP CONSTRAINT `chk_warehouses_kind_branch`');

        DB::statement("ALTER TABLE `warehouses` MODIFY `kind` ENUM('central', 'branch', 'transit') NOT NULL");

        // El tránsito no pertenece a ninguna sucursal, por la misma razón que el central: la mercancía en viaje
        // ya salió de una y todavía no llegó a la otra, así que atribuirla a cualquiera de las dos sería falso.
        DB::statement(<<<'SQL'
            ALTER TABLE `warehouses`
            ADD CONSTRAINT `chk_warehouses_kind_branch` CHECK (
                (`kind` = 'central' AND `branch_id` IS NULL) OR
                (`kind` = 'transit' AND `branch_id` IS NULL) OR
                (`kind` = 'branch'  AND `branch_id` IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `warehouses`
            ADD COLUMN `transit_key` TINYINT UNSIGNED
                GENERATED ALWAYS AS (IF(`kind` = 'transit', 1, NULL)) STORED
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE `warehouses`
            ADD UNIQUE `warehouses_one_transit_per_tenant` (`tenant_id`, `transit_key`)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `warehouses` DROP INDEX `warehouses_one_transit_per_tenant`');
        DB::statement('ALTER TABLE `warehouses` DROP COLUMN `transit_key`');
        DB::statement('ALTER TABLE `warehouses` DROP CONSTRAINT `chk_warehouses_kind_branch`');

        // Los almacenes de tránsito se van con el enum: si quedara alguno, el `MODIFY` fallaría y la reversión
        // se quedaría a medias. No se pierde nada reconstruible — tránsito no debe tener saldo entre viajes.
        DB::statement("DELETE FROM `warehouses` WHERE `kind` = 'transit'");
        DB::statement("ALTER TABLE `warehouses` MODIFY `kind` ENUM('central', 'branch') NOT NULL");

        DB::statement(<<<'SQL'
            ALTER TABLE `warehouses`
            ADD CONSTRAINT `chk_warehouses_kind_branch` CHECK (
                (`kind` = 'central' AND `branch_id` IS NULL) OR
                (`kind` = 'branch'  AND `branch_id` IS NOT NULL)
            )
        SQL);
    }
};
