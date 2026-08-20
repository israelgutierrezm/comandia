<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `expenses` — el dinero que sale. INMUTABLE.
 *
 * ## Por qué el gasto desde caja es NECESARIO y no un extra
 *
 * D235 lo puso en la lista de cuatro cosas sin las que el POS no funciona, y la razón es aritmética: **el cajero paga
 * los garrafones con dinero de la caja**. Un arqueo que no conoce esa salida no cuadra nunca, y una diferencia que
 * siempre está deja de significar nada — que es peor que no tener arqueo, porque nadie vuelve a mirarla.
 *
 * ## `source` distingue lo que toca el cajón de lo que no
 *
 * Un gasto **desde caja** sale del efectivo del turno y entra en el arqueo. Uno **fuera de caja** —una transferencia
 * desde la oficina, la tarjeta de la empresa— es gasto del negocio y no toca la caja de nadie. Mezclarlos haría que el
 * arqueo del cajero cargara con la renta del local.
 *
 * De ahí las dos columnas condicionales: `pos_session_id` obligatorio si sale de caja —tiene que pertenecer a un turno—
 * y `payment_method_id` para los de fuera, que necesitan decir por dónde se pagó.
 *
 * ## `receipt_path` es OPCIONAL, y es una decisión
 *
 * §6.5 lo dice y conviene entender por qué: exigir comprobante haría que el gasto de 40 pesos de hielo no se registrara.
 * Un gasto sin comprobante registrado es infinitamente mejor que un gasto sin registrar — el primero descuadra el arqueo
 * de forma explicable, el segundo lo descuadra y punto.
 *
 * ## Append-only
 *
 * Un gasto es dinero que salió. Editarlo cambiaría un arqueo ya cerrado; corregirlo es registrar su reversa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->foreignId('expense_category_id')
                ->constrained('expense_categories')
                ->restrictOnDelete();

            $table->enum('source', ['cash_session', 'outside_cash']);

            $table->foreignId('pos_session_id')
                ->nullable()
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            // NOT NULL: «¿en qué se fue ese dinero?» es la pregunta del arqueo, y una categoría sola no la contesta.
            // «Gastos varios: $800» no explica nada.
            $table->string('description', 300);

            $table->string('receipt_path', 300)->nullable();

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Quién puso el PIN, por encima del umbral. Nullable porque por debajo no hace falta.
            $table->foreignId('authorized_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'expenses_ulid_unique');

            // El arqueo: «qué salió de esta caja». Es la consulta del corte.
            $table->index(['tenant_id', 'pos_session_id'], 'expenses_session_index');

            // El reporte de gastos por sucursal y fecha, que es como se mira el gasto corriente de un negocio.
            $table->index(['tenant_id', 'branch_id', 'occurred_at'], 'expenses_branch_date_index');

            // «Cuánto llevo gastado en gas este mes».
            $table->index(['tenant_id', 'expense_category_id', 'occurred_at'], 'expenses_category_index');
        });

        DB::statement('ALTER TABLE `expenses` ADD CONSTRAINT `expenses_amount_chk` CHECK (`amount` > 0)');

        // Un gasto de caja pertenece a un turno; uno de fuera, no — y lleva método de pago porque hay que poder decir
        // por dónde salió. Sin esto, un gasto de caja sin sesión sería dinero que salió de ningún cajón.
        DB::statement(<<<'SQL'
            ALTER TABLE `expenses`
            ADD CONSTRAINT `expenses_source_shape_chk` CHECK (
                (`source` = 'cash_session' AND `pos_session_id` IS NOT NULL) OR
                (`source` = 'outside_cash' AND `pos_session_id` IS NULL AND `payment_method_id` IS NOT NULL)
            )
        SQL);

        DB::statement("ALTER TABLE `expenses` ADD CONSTRAINT `expenses_description_chk` CHECK (CHAR_LENGTH(TRIM(`description`)) >= 3)");
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
