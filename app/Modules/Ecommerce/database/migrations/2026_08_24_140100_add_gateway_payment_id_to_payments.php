<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El id del cargo en la pasarela, para poder reembolsar (Iteración 8, Tanda D, D2 parte 2).
 *
 * La `gateway_reference` casa el aviso con el pedido (es el ULID del pedido). Pero el reembolso se hace contra el **cargo**,
 * que la pasarela identifica con SU propio id —`payment_intent` en Stripe, el id del pago en Mercado Pago—, distinto de la
 * referencia. La parte 3b no lo guardaba; se captura ahora al confirmar el webhook. Nullable: los pagos de la pasarela de
 * prueba (y los ya existentes) no lo tienen, y no lo necesitan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('gateway_payment_id', 191)->nullable()->after('gateway_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('gateway_payment_id'));
    }
};
