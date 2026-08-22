<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El registro POR VENTA de una promoción aplicada (§6.3).
 *
 * ## Append-only, como su hermano manual
 *
 * §6.3 exige registrar «la promoción aplicada y el monto descontado» por cada venta. Es el hermano automático de
 * `pos_discounts` —dinero retirado de una venta— y vive, como él, en la «zona de máxima auditoría»: ambos alimentan el
 * reporte antifraude de §9. Por eso es INMUTABLE (D312): se escribe una vez al cobrar, nunca se reescribe; una
 * corrección es una reversa, no un UPDATE. El trait `Immutable` y `ImmutableTablesTest` lo vigilan en las dos
 * direcciones.
 *
 * ## Referencia la cuenta por ULID, sin FK
 *
 * `pos_accounts` y `pos_order_items` son de `Pos`; este módulo es `Promotions`. Una FK haría que `Promotions` dependiera
 * de `Pos`, y la dependencia va al revés (el POS pregunta por el probe). Así que la cuenta, la línea y el descuento se
 * citan por su ULID —primitivos que llegan en el evento (D231/D151)—, no por llave interna. La verdad monetaria vive en
 * el `pos_discount` que este registro acompaña; aquí se guarda para qué promoción fue y cuánto, que es lo que el reporte
 * por promoción necesita.
 *
 * ## Idempotente por el descuento origen
 *
 * Un evento re-despachado no duplica el registro: `unique(tenant_id, pos_discount_ulid)`. Cada fila de `pos_discounts`
 * con origen promoción produce a lo sumo un registro aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_applications', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // La definición que se aplicó. `restrict`: no se borra una promoción que ya dejó historia (igual que no se
            // borra un método de pago usado). Para retirarla se pone `inactive`.
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();

            // Documentos de `Pos`, por ULID y sin FK (frontera de módulo).
            $table->char('pos_account_ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->char('pos_order_item_ulid', 26)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('pos_discount_ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->decimal('amount_discounted', 12, 2);

            $table->timestamp('applied_at');

            // Inmutable: sólo `created_at`, sin `updated_at` (el trait Immutable apaga los timestamps de actualización).
            $table->timestamp('created_at')->nullable();

            $table->unique('ulid', 'promotion_applications_ulid_unique');

            // Idempotencia: un descuento-origen, un registro.
            $table->unique(['tenant_id', 'pos_discount_ulid'], 'promotion_applications_discount_unique');

            // El reporte por promoción: cuántas veces y cuánto descontó cada una, en un rango de fechas.
            $table->index(['tenant_id', 'promotion_id', 'applied_at'], 'promotion_applications_report_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `promotion_applications`
            ADD CONSTRAINT `chk_promotion_applications_amount` CHECK (`amount_discounted` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_applications');
    }
};
