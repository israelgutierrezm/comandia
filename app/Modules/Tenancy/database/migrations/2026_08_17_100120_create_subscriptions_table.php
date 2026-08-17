<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `subscriptions` — la suscripción como entidad propia (D4).
 *
 * SIN precios ni importes a propósito: el cobro real se implementa al final del
 * proyecto y la forma comercial exacta no está definida
 * (ESPECIFICACIÓN_MAESTRA §2). Meter montos hoy sería inventar el modelo
 * comercial; lo que sí existe desde el día uno es la entidad y su periodo, que
 * es lo que la arquitectura necesita para medir y limitar.
 *
 * Regla que esta tabla hace cumplir: ninguna lógica de negocio consulta "el
 * plan". Consulta `tenant_limits` y `tenant_modules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->enum('status', ['active', 'past_due', 'cancelled'])->default('active');

            $table->date('started_at');
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->date('cancelled_at')->nullable();

            $table->timestamps();

            $table->unique('ulid', 'subscriptions_ulid_unique');

            // Resolver "la suscripción vigente de este tenant". NO se puede hacer
            // único (tenant_id, status): habrá suscripciones canceladas históricas,
            // y varias. La regla "una activa a la vez" la valida el servicio de
            // aplicación, no la base.
            $table->index(['tenant_id', 'status'], 'subscriptions_tenant_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
