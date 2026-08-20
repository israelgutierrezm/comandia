<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `pos_accounts` — la cuenta, lo que se cobra (D28, §6.3).
 *
 * ## Cuenta, orden y comanda son TRES cosas
 *
 * Una cuenta de cuatro personas que piden tres veces a lo largo de dos horas tiene **una cuenta, tres órdenes y hasta
 * nueve comandas**. Confundirlas es el error clásico de los POS: al dividir la cuenta en cuatro, las comandas ya
 * impresas no cambian — porque describen algo que ya ocurrió.
 *
 * ## Los totales son PROYECCIÓN, no verdad
 *
 * Se recalculan de los items y de los pagos dentro de la misma transacción que los modifica, igual que
 * `article_stocks` se recalcula del kardex. La verdad son las líneas; el total existe para no sumar veinte filas en cada
 * refresco de la pantalla de piso, que durante el servicio es constantemente.
 *
 * ## `version` es el candado optimista, y §11 lo pide por nombre
 *
 * «Versión de cuenta verificada al pagar». Se incrementa en cada escritura y quien cobra manda la versión que leyó; si
 * no coincide, recibe 409 y vuelve a leer. Un lock pesimista serviría igual contra el cobro doble, pero bloquearía al
 * mesero que está capturando mientras el cajero mira la pantalla — y en un POS táctil eso se nota.
 *
 * ## Lo que NACE nullable esperando su iteración
 *
 * `customer_id` no tiene FK todavía: `customers` llega en el paso 17. La columna existe desde ahora porque añadirla
 * después obligaría a un `ALTER` sobre la tabla más consultada del sistema. Queda anotado, como quedó anotada la FK de
 * `pos_session_id` en el paso 4 — y ésa ya está cerrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_accounts', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Foliación por (tenant, sucursal, tipo, serie), sin huecos (§7, D53). Es el número que el cliente ve en su
            // cuenta, así que un hueco es una pregunta incómoda.
            $table->char('series', 4)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('folio');

            $table->enum('kind', ['dine_in', 'takeout']);

            $table->enum('status', ['open', 'bill_requested', 'closed', 'paid', 'cancelled'])->default('open');

            // La mesa. `null` en barra y para llevar, que son casos legítimos y frecuentes (§6.3).
            $table->foreignId('table_id')
                ->nullable()
                ->constrained('restaurant_tables')
                ->restrictOnDelete();

            // El nombre libre de una cuenta sin mesa: «Barra 3», «Señor de lentes». Es lo que hace operable una barra
            // sin obligar a inventar mesas.
            $table->string('label', 60)->nullable();

            // El cliente, cuando se identifica. SIN clave foránea hasta el paso 17 — ver la nota de arriba.
            $table->unsignedBigInteger('customer_id')->nullable();

            // El número que se GRITA en el mostrador. Un folio de cinco cifras no sirve para eso.
            $table->unsignedSmallInteger('takeout_number')->nullable();

            $table->enum('delivery_status', ['pending', 'ready', 'delivered'])->nullable();

            // El TITULAR de la cuenta (D233): a él se le atribuyen las propinas. NOT NULL — toda cuenta tiene uno,
            // incluida una de barra que abrió el propio cajero.
            $table->foreignId('waiter_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Nullable mientras no se cobra: una cuenta se abre sin que haya caja abierta —el mesero toma la orden—, y
            // sin sesión no se puede PAGAR (§6.3). Al pagar deja de ser nulo.
            $table->foreignId('pos_session_id')
                ->nullable()
                ->constrained('pos_sessions')
                ->restrictOnDelete();

            $table->foreignId('opened_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('opened_at');
            $table->timestamp('bill_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('cancelled_reason', 300)->nullable();

            // Proyecciones. Se recalculan de las líneas; no son la verdad.
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_total', 12, 2)->default(0);
            $table->decimal('tip_total', 12, 2)->default(0);
            $table->decimal('change_total', 12, 2)->default(0);

            // De qué cuenta salió al dividir (§ operaciones de cuenta, paso 12).
            $table->foreignId('parent_account_id')
                ->nullable()
                ->constrained('pos_accounts')
                ->restrictOnDelete();

            $table->unsignedInteger('version')->default(0);

            $table->timestamps();

            $table->unique('ulid', 'pos_accounts_ulid_unique');
            $table->unique(['tenant_id', 'branch_id', 'series', 'folio'], 'pos_accounts_folio_unique');

            // El piso: «cuentas abiertas de esta sucursal», ordenadas por cuándo se abrieron.
            $table->index(['tenant_id', 'branch_id', 'status', 'opened_at'], 'pos_accounts_floor_index');

            // El corte agrupa por sesión.
            $table->index(['tenant_id', 'pos_session_id'], 'pos_accounts_session_index');

            // Las propinas de un mesero, que la liquidación del paso 18 necesita.
            $table->index(['tenant_id', 'waiter_membership_id', 'paid_at'], 'pos_accounts_waiter_index');

            // «¿Qué mesas están ocupadas?» y «¿queda alguna cuenta abierta en esta mesa?», que es lo que decide si la
            // mesa se libera al pagar (§6.3).
            $table->index(['tenant_id', 'table_id', 'status'], 'pos_accounts_table_index');
        });

        // Los totales no pueden ser negativos. Y NO hay un CHECK que ate `status = 'paid'` a `paid_total >= total`: una
        // cortesía es una venta en $0 legítima (§6.3) y un descuento del 100 % también.
        foreach (['total', 'paid_total', 'tip_total', 'subtotal', 'vat_total', 'discount_total'] as $columna) {
            DB::statement(sprintf(
                'ALTER TABLE pos_accounts ADD CONSTRAINT pos_accounts_%s_not_negative CHECK (%s >= 0)',
                $columna,
                $columna,
            ));
        }

        // Una cuenta de comer aquí NO lleva número de para llevar, y una para llevar no lleva mesa. Va como CHECK
        // porque es un invariante de forma, no una preferencia: una cuenta con mesa Y número de mostrador no se puede
        // ni pintar en una pantalla.
        DB::statement(
            "ALTER TABLE pos_accounts ADD CONSTRAINT pos_accounts_kind_shape CHECK ("
            ."(kind = 'takeout' AND table_id IS NULL) OR (kind = 'dine_in' AND takeout_number IS NULL)"
            .')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_accounts');
    }
};
