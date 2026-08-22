<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El costo del momento, congelado en la línea de venta (Iteración 7, D322).
 *
 * El reporte de margen necesita `utilidad = precio_neto − costo` con el costo REAL al momento de la venta, no el vigente
 * hoy: un cambio de costo posterior no debe reescribir el margen de una venta vieja (D320). Se congela como ya se congelan
 * `unit_price` y `vat_rate`. El valor lo provee `Costing` por la sonda `ProductCostProbe` al capturar; si no se sabe, `0`.
 *
 * Escala 12,4 —la de los costos unitarios (§7), no la del dinero 12,2—: un costo puede tener más precisión que un precio.
 * `default(0)`: las líneas ya capturadas antes de esta iteración no tienen costo conocido y quedan en `0`, coherente con
 * el null-object de la sonda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 12, 4)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('pos_order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
