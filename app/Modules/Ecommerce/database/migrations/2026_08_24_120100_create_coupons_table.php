<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cupones de la tienda en línea (Iteración 8, Tanda D, D3).
 *
 * Un cupón es un código que el cliente teclea en el checkout para un descuento acotado: un porcentaje, un monto fijo, o
 * envío gratis. Con vigencia opcional, tope de usos global y límite por cliente. Es propio del e-commerce y por eso vive
 * aquí y no en `promotions` (It.6): aquéllas se aplican solas por artículo/categoría en la caja, sin código ni canal en
 * línea (D341/exploración). El canje se registra aparte (parte 2), inmutable, para contar usos y ser idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            $table->string('code', 40);
            $table->enum('type', ['percentage', 'fixed', 'free_shipping']);

            // Para `percentage` es el % (1–100); para `fixed`, el monto; para `free_shipping`, cero (el descuento es el envío).
            $table->decimal('value', 12, 2);

            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            // `null` = sin tope. `uses_count` lo incrementa el canje (parte 2), no la administración.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('ulid', 'coupons_ulid_unique');
            // El código es único por negocio: `/t/{slug}` ya resuelve el tenant, así que dos negocios pueden usar «BIENVENIDO».
            $table->unique(['tenant_id', 'code'], 'coupons_code_unique');
            $table->index(['tenant_id', 'store_id'], 'coupons_store_index');
        });

        // El valor tiene sentido según el tipo: un porcentaje va de 1 a 100; un monto fijo es positivo; el envío gratis no
        // lleva valor. Sin esto, un cupón de «120 %» o un fijo de «−50» pasarían.
        DB::statement(<<<'SQL'
            ALTER TABLE `coupons`
            ADD CONSTRAINT `coupons_value_shape_chk` CHECK (
                (`type` = 'percentage' AND `value` > 0 AND `value` <= 100) OR
                (`type` = 'fixed' AND `value` > 0) OR
                (`type` = 'free_shipping' AND `value` = 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
