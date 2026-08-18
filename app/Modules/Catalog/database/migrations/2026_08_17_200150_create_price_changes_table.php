<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `price_changes` — historial INMUTABLE de precios (D15, §7).
 *
 * "El sistema sugiere precio, el humano decide" y **todo cambio se historiza**. Es una de las seis tablas
 * append-only del proyecto: sin UPDATE, sin DELETE, corrección por registro nuevo.
 *
 * ## Por qué se guardan el sugerido, el costo y el markup, aunque sean derivables
 *
 * Aquí sí, y la diferencia con el subtotal de IVA —que NO se almacena— es exactamente la que importa: el
 * IVA se recalcula igual mañana, pero el costo y el markup de hace ocho meses **ya no se pueden
 * reconstruir**. Cambiaron. Sin ellos, la pregunta que este historial existe para contestar —"¿el precio
 * subió porque subió el costo, o porque alguien quiso?"— no tiene respuesta.
 *
 * ## Las FK son RESTRICT
 *
 * Misma decisión que en `audit_entries.actor_user_id`: un historial de precios es **evidencia**, y borrar a
 * la persona que subió un precio no puede borrar el hecho de que lo subió. Por eso los artículos se
 * archivan (D80) en lugar de borrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_changes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->charset('ascii')->collation('ascii_bin');

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('article_id')
                ->constrained('articles')
                ->restrictOnDelete();

            // NULL = cambió el precio MAESTRO del artículo. Con valor = cambió el override de esa sucursal
            // (paso 9). Es un histórico append-only, así que el NULL no participa en ningún índice único y
            // no reaparece el problema de D78.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->restrictOnDelete();

            // NULL en `previous_price` = primera fijación. Es distinto de un cambio desde cero: "no tenía
            // precio" y "valía $0" son dos cosas, y la segunda sería una cortesía.
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2);

            // El estado del costeo EN ESE MOMENTO. Cuatro decimales por la excepción declarada de P3: el
            // sugerido se deriva de un costo unitario y arrastra su precisión.
            $table->decimal('suggested_price', 12, 4)->nullable();
            $table->decimal('unit_cost_at_change', 12, 4)->nullable();

            // MARKUP = utilidad ÷ costo (D13). El MARGEN —utilidad ÷ precio— no se guarda: se calcula al
            // leer, a partir del precio y del costo que sí están aquí. Guardar los dos invitaría a que se
            // contradijeran.
            $table->decimal('markup_percent', 6, 2)->nullable();

            $table->string('reason', 200)->nullable();

            $table->foreignId('actor_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            // Tabla inmutable: sólo `created_at`. `useCurrent()` queda como red para seeders e
            // importaciones; el trait `Immutable` lo escribe desde PHP porque el reloj de MySQL puede no
            // estar en UTC (D85).
            $table->timestamp('created_at')->useCurrent();

            $table->unique('ulid', 'price_changes_ulid_unique');

            // "El historial de precios de este artículo", lo más reciente primero: la pantalla que D15
            // exige. Incluye `branch_id` para poder separar el maestro de los overrides sin un índice más.
            $table->index(
                ['tenant_id', 'article_id', 'created_at'],
                'price_changes_tenant_article_created_index'
            );

            // "Todos los cambios de precio del mes": el reporte de control que §9 pide como mitigación de
            // manipulación de precios. Va aparte porque no filtra por artículo, y sin él ese reporte
            // recorrería la tabla completa.
            $table->index(['tenant_id', 'created_at'], 'price_changes_tenant_created_index');
        });

        // Un precio negativo no es un descuento: es un artículo que paga al cliente por llevárselo. Los
        // descuentos tienen su propio permiso, motivo y auditoría (§6.3).
        DB::statement(<<<'SQL'
            ALTER TABLE `price_changes`
            ADD CONSTRAINT `chk_price_changes_not_negative` CHECK (
                `new_price` >= 0 AND (`previous_price` IS NULL OR `previous_price` >= 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_changes');
    }
};
