<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_methods` — con qué se cobra (§6.3, adelantado a esta iteración por D232).
 *
 * ## Por qué es la primera tabla de `Finance` y no del POS
 *
 * Un pago sin método no se puede registrar, así que el POS la necesita antes de cobrar nada. Pero el método **no es
 * del POS**: el corte agrupa por método, los gastos fuera de caja eligen método, y los abonos de crédito también. Es
 * vocabulario financiero del negocio, y por eso vive donde vive el diario.
 *
 * ## `affects_cash_drawer` es la columna que hace posible el arqueo
 *
 * El corte compara lo que **debería** haber en el cajón contra lo declarado, y para eso hace falta saber qué pagos
 * entraron ahí de verdad. El efectivo sí; la tarjeta no —el dinero llega al banco días después—; el crédito del cliente
 * tampoco, porque no ha entrado nada. Sin esta bandera el arqueo sumaría las tarjetas al efectivo esperado y **toda**
 * caja saldría descuadrada.
 *
 * Y en el diario esta bandera se **copia** al asentar, no se lee después: si mañana alguien cambia la configuración del
 * método, los cortes de ayer no deben cambiar.
 *
 * ## Se da de baja, no se borra
 *
 * `pos_payments` citará el método con `RESTRICT`, así que borrarlo sería imposible en cuanto exista un cobro — y debe
 * serlo: un pago que no puede decir con qué se pagó no explica nada. El estado `inactive` lo saca de los botones de la
 * caja sin tocar el histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // 30 y no 20 como en el resto: los métodos del sistema llevan códigos legibles y estables
            // (`CUSTOMER_CREDIT` son 15), y un negocio puede querer `VALES_DESPENSA`.
            $table->char('code', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('name', 60);

            $table->enum('kind', ['cash', 'card', 'transfer', 'customer_credit', 'custom']);

            // Las tres banderas de comportamiento. Van como columnas y no derivadas de `kind` porque un método
            // `custom` las declara por su cuenta: un vale de despensa no afecta el cajón, y una tarjeta de regalo
            // propia podría dar cambio si el negocio lo decide.
            $table->boolean('affects_cash_drawer')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('allows_change')->default(false);

            // Los del sistema no se borran ni se renombran: son la referencia compartida con cortes y reportes. El
            // invariante lo impone el modelo; la columna existe para que la interfaz sepa qué no ofrecer.
            $table->boolean('is_system')->default(false);

            $table->enum('status', ['active', 'inactive'])->default('active');

            // El orden de los botones en la caja. Que el efectivo esté primero no es estética: es el método más usado
            // y cada toque de más se paga en la fila.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'payment_methods_ulid_unique');
            $table->unique(['tenant_id', 'code'], 'payment_methods_tenant_code_unique');

            // La consulta de la caja es «los métodos activos, en su orden». Es la que corre en cada cobro, así que va
            // indexada por lo que filtra y por lo que ordena.
            $table->index(['tenant_id', 'status', 'sort_order'], 'payment_methods_tenant_status_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
