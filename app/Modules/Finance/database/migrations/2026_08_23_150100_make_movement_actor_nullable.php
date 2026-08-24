<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El actor de un asiento del diario pasa a **nullable** (Iteración 8, Tanda C parte 3).
 *
 * Hasta ahora todo movimiento lo hacía un miembro del personal (una venta del POS, un gasto, un retiro). La tienda en
 * línea estrena el primer asiento **automático sin actor de personal**: una venta de e-commerce la origina el cliente
 * desde su casa, no un cajero. Atribuirla al propietario mentiría (la bitácora del proyecto evita justo eso); por eso el
 * actor puede faltar. Esos movimientos llevan `pos_session_id` null, así que no entran en ningún corte de caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->dropForeign(['actor_membership_id']);
        });

        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->foreignId('actor_membership_id')->nullable()->change();
            $table->foreign('actor_membership_id')->references('id')->on('tenant_memberships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->dropForeign(['actor_membership_id']);
        });

        Schema::table('financial_movements', function (Blueprint $table): void {
            $table->foreignId('actor_membership_id')->nullable(false)->change();
            $table->foreign('actor_membership_id')->references('id')->on('tenant_memberships')->restrictOnDelete();
        });
    }
};
