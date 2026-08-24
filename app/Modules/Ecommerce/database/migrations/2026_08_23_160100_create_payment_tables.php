<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de la tienda y configuración de pasarela (Iteración 8, Tanda C parte 3, D49).
 *
 * ## `payments` es INMUTABLE (CLAUDE.md)
 *
 * Una fila se crea cuando el pago se **confirma** (webhook aprobado), y no se edita nunca: un reembolso será una fila de
 * reversa enlazada. El estado «pendiente» vive en el pedido, no aquí. `unique(gateway, reference)` hace **idempotente** el
 * webhook: un aviso repetido no crea un segundo pago.
 *
 * ## Credenciales cifradas, sin JSON
 *
 * Una pasarela activa por negocio (D49). Las claves secretas se cifran en reposo (cast `encrypted`) y jamás vuelven por la
 * API. Se usan columnas discretas —clave pública, secreta y de webhook— y no un JSON, porque los secretos son datos de
 * dominio (CLAUDE.md: JSON sólo en auditoría e impresión).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // La pasarela activa: 'mercadopago' | 'stripe' | null (ninguna). Una a la vez (D49).
            $table->string('active_gateway', 40)->nullable();

            // La pública no es secreta; las otras dos se cifran.
            $table->string('public_key', 255)->nullable();
            $table->text('secret_key')->nullable();     // cifrada
            $table->text('webhook_secret')->nullable(); // cifrada

            $table->timestamps();

            $table->unique('tenant_id', 'payment_gateway_settings_tenant_unique');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('gateway', 40);
            $table->string('gateway_reference', 191);
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['approved', 'refunded'])->default('approved');

            $table->timestamp('confirmed_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'payments_ulid_unique');
            // Idempotencia del webhook: un aviso repetido de la misma pasarela+referencia no duplica el pago.
            $table->unique(['tenant_id', 'gateway', 'gateway_reference'], 'payments_gateway_reference_unique');
            $table->index(['tenant_id', 'order_id'], 'payments_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_gateway_settings');
    }
};
