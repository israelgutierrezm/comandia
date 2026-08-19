<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `waste_reasons.is_system` — motivos que el sistema necesita y el negocio no administra.
 *
 * Lo abre el paso 6: la recepción con diferencias genera una merma automática y esa merma necesita un motivo. Si
 * fuera un motivo normal, alguien podría renombrarlo a «se cayó al piso» o darlo de baja, y la siguiente recepción
 * con diferencias fallaría —o peor, agruparía sus pérdidas bajo un motivo que significa otra cosa, volviendo
 * mentiroso el reporte que D27 existe para dar.
 *
 * Un motivo del sistema no se renombra ni se da de baja. Sí se puede **cambiar su exigencia de evidencia**: eso es
 * política del negocio y no cambia lo que el motivo significa.
 *
 * Es la misma distinción que los roles del sistema en la Iteración 1: existen, se usan, y no se editan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waste_reasons', function (Blueprint $table): void {
            // `default false`: todo motivo que el negocio dé de alta es suyo. Sólo el sistema pone el `true`, y
            // nunca por una petición — no está en el Form Request.
            $table->boolean('is_system')->default(false)->after('requires_evidence');
        });
    }

    public function down(): void
    {
        Schema::table('waste_reasons', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
