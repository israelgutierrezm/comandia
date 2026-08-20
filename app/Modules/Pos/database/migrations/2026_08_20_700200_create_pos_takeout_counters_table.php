<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_takeout_counters` — el número que se grita en el mostrador.
 *
 * ## Por qué el folio de la cuenta no sirve
 *
 * §6.3 pide «numeración visible» para el mostrador, y el folio de una cuenta es un número que crece para siempre: a los
 * tres meses va por A-14238. Nadie grita eso, y quien lo oye no lo retiene. El número de mostrador vuelve a 1 cada
 * jornada y se queda en dos cifras.
 *
 * ## Por qué una TABLA y no `MAX(takeout_number) + 1`
 *
 * Dos pedidos simultáneos leerían el mismo máximo y gritarían el mismo número — dos personas levantándose por la misma
 * bolsa. Con una fila por (negocio, sucursal, jornada) y un `FOR UPDATE`, el segundo espera.
 *
 * ## Y por qué NO se reutiliza `DocumentNumberAllocator`
 *
 * Resuelve exactamente el mismo problema de concurrencia y **no sirve aquí**: aquél no reinicia nunca, y el reinicio
 * diario es el requisito entero. Forzarlo —borrando su fila cada noche— rompería su invariante de «sin huecos» y
 * mezclaría dos conceptos que se leen igual y significan cosas distintas: un folio es un documento, esto es una etiqueta
 * que se recicla.
 *
 * ## `business_date` es una FECHA y no una marca de tiempo
 *
 * La jornada de un restaurante no termina a medianoche: un pedido de la 1:30 de la madrugada pertenece al día anterior.
 * Guardar la fecha como dato —resuelta con la zona horaria de la sucursal— permite decidir a qué jornada pertenece cada
 * pedido sin que la respuesta dependa de dónde corra el servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_takeout_counters', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->date('business_date');

            $table->unsignedSmallInteger('last_number')->default(0);

            $table->timestamps();

            // Una fila por jornada y sucursal. Es el único que hace que el `FOR UPDATE` tenga algo que bloquear: sin
            // él, dos peticiones simultáneas del primer pedido del día crearían dos filas y darían los dos el número 1.
            $table->unique(['tenant_id', 'branch_id', 'business_date'], 'pos_takeout_counters_day_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_takeout_counters');
    }
};
