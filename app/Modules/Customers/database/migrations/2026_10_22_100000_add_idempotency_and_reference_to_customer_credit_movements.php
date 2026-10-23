<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonos sin duplicados, con su referencia, y ajustes de saldo autorizados (D378).
 *
 * - `idempotency_key`: la llave que manda quien registra un abono o un ajuste, una por intento. Un doble clic —o la red
 *   que se cae y el reintento— registraba dos abonos; ahora el segundo devuelve el primero. Va en su propia columna y no
 *   en `source_*`: ésas dicen de qué documento salió el movimiento (la cuenta fiada) y la API las publica; la llave es un
 *   dato de la petición.
 * - `reference`: el folio de la transferencia o la autorización del voucher, obligatoria cuando el método la pide (como
 *   en los pagos del punto de venta, 60 caracteres).
 * - `notes`: el motivo de un ajuste. Sin motivo, una condonación sería dinero perdonado que nadie puede explicar.
 * - `authorized_by_membership_id`: quien puso su PIN para el ajuste; el actor real de una acción sensible.
 *
 * ## Índices
 *
 * - `(tenant_id, idempotency_key)` ÚNICO: es el candado contra el duplicado —dos peticiones con la misma llave chocan
 *   aquí aunque lleguen a la vez—. MySQL admite varios `NULL` en un único, así que los cargos del POS, que no la llevan,
 *   no chocan entre sí.
 * - `authorized_by_membership_id`: el índice que exige su llave foránea; ninguna consulta nueva lo usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_credit_movements', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->charset('ascii')->collation('ascii_bin')->nullable()->after('pos_session_id');
            $table->string('reference', 60)->nullable()->after('idempotency_key');
            $table->string('notes', 300)->nullable()->after('reference');

            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->after('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->unique(['tenant_id', 'idempotency_key'], 'customer_credit_movements_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_movements', function (Blueprint $table): void {
            $table->dropUnique('customer_credit_movements_idempotency_unique');
            $table->dropConstrainedForeignId('authorized_by_membership_id');
            $table->dropColumn(['idempotency_key', 'reference', 'notes']);
        });
    }
};
