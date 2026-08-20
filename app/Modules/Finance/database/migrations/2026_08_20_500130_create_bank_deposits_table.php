<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `bank_deposits` — el dinero que llegó al banco. INMUTABLE.
 *
 * ## Cierra el retiro, y sin él el retiro deja el efectivo en un limbo
 *
 * El dinero sale de la caja con un `withdrawal` (paso 6) y entra al banco con un `deposit`. Sin la segunda mitad, un
 * retiro de diez mil pesos es una salida declarada que no llega a ningún sitio: el arqueo cuadra —el dinero salió— y
 * nadie puede decir dónde está. Con las dos, el recorrido completo queda escrito.
 *
 * ## Referencia bancaria SIMPLE (D38): banco, fecha, folio
 *
 * Sin conciliación. Conciliar es cruzar el estado de cuenta del banco contra estos registros, y eso exige leer archivos
 * del banco, formatos por institución y un motor de emparejamiento — una iteración entera. Lo que hace falta ahora es
 * poder contestar «¿este retiro llegó al banco?», y para eso basta el folio del comprobante.
 *
 * ## `deposited_on` es DATE y no timestamp
 *
 * Un depósito se hace en una fecha, no en un instante: quien lo captura lo hace después, con el comprobante en la mano,
 * y la hora exacta del cajero automático no le sirve a nadie. Guardar un timestamp obligaría a inventarse una hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_deposits', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->string('bank_name', 60);

            // El folio del comprobante. NOT NULL: un depósito sin referencia no se puede buscar en el estado de cuenta,
            // que es lo único para lo que sirve registrarlo.
            $table->string('reference', 60);

            $table->date('deposited_on');

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'bank_deposits_ulid_unique');

            // La consulta es «qué deposité este mes», por sucursal y fecha.
            $table->index(['tenant_id', 'branch_id', 'deposited_on'], 'bank_deposits_branch_date_index');
        });

        DB::statement('ALTER TABLE `bank_deposits` ADD CONSTRAINT `bank_deposits_amount_chk` CHECK (`amount` > 0)');
        DB::statement("ALTER TABLE `bank_deposits` ADD CONSTRAINT `bank_deposits_reference_chk` CHECK (CHAR_LENGTH(TRIM(`reference`)) >= 1)");
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_deposits');
    }
};
