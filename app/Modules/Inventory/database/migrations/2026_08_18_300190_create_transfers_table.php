<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `transfers` — el traslado de mercancía entre dos almacenes como documento (D25, §6.2).
 *
 * ## Cinco sellos, no un campo de estado con fecha
 *
 * Cada paso guarda **quién** y **cuándo** por separado. Un solo par `updated_by`/`updated_at` contestaría «quién la
 * tocó al último» y la pregunta que importa es otra: «¿quién autorizó esto y quién lo recibió?». Con un solo par,
 * en cuanto la recibe alguien se pierde para siempre quién la había autorizado — que es justo lo que se busca
 * cuando falta mercancía.
 *
 * Los cinco son nulables porque dos pasos son omitibles por configuración, y un sello nulo dice «este paso no se
 * pedía entonces». Activar la autorización el año que viene no reinterpreta las transferencias viejas.
 *
 * ## Folio por sucursal, y de ahí sale una restricción
 *
 * §7 exige foliación por (tenant, sucursal, tipo, serie) sin huecos, y un almacén central no tiene sucursal. El
 * folio sale de la sucursal del **origen**, o del destino si el origen es central. Una transferencia entre dos
 * almacenes centrales no tendría ninguna, y **se rechaza en v1** con un mensaje explícito: exige que el negocio
 * tenga dos bodegas centrales, que es raro, y la alternativa —volver nulable la sucursal en `document_sequences`—
 * toca la tabla donde §7 es más explícito y obliga a repetir el truco de la columna generada para que el índice
 * único siga deduplicando. La evolución, si aparece la necesidad, es una serie a nivel de negocio.
 *
 * ## Origen y destino son RESTRICT
 *
 * Una transferencia recibida es evidencia de que mercancía cambió de sitio, con dos kardex que la citan. Que
 * desaparezca porque alguien borró un almacén dejaría cuatro movimientos sin documento que los explique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            $table->foreignId('origin_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('destination_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->string('status', 30);

            // La sucursal de la que sale el folio, congelada. Se guarda en lugar de recalcularse desde el almacén
            // porque la regla —origen, o destino si el origen es central— podría cambiar, y entonces el folio de
            // una transferencia vieja dejaría de poder explicarse.
            $table->foreignId('folio_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->char('series', 8)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('folio');

            // Los cinco sellos. RESTRICT: son la parte del documento que contesta «¿quién?», y una membresía no se
            // borra, se da de baja.
            foreach (['requested', 'authorized', 'prepared', 'shipped', 'received'] as $step) {
                $table->foreignId("{$step}_by_membership_id")
                    ->nullable()
                    ->constrained('tenant_memberships')
                    ->restrictOnDelete();

                $table->timestamp("{$step}_at")->nullable();
            }

            $table->string('notes', 300)->nullable();

            $table->timestamps();

            // El folio es único por (negocio, sucursal, serie): es la garantía de §7, y sin índice sería una
            // promesa que dos peticiones simultáneas rompen. El tipo de documento no entra porque esta tabla ya
            // ES de un solo tipo — la secuencia sí lo lleva.
            $table->unique(['tenant_id', 'folio_branch_id', 'series', 'folio'], 'transfers_folio_unique');

            // «Las transferencias de este almacén, de la más reciente a la más vieja»: es la pantalla de la
            // sección. Dos índices porque son dos preguntas —lo que sale y lo que entra— y un almacén necesita
            // las dos.
            $table->index(['tenant_id', 'origin_warehouse_id', 'created_at'], 'transfers_tenant_origin_index');
            $table->index(['tenant_id', 'destination_warehouse_id', 'created_at'], 'transfers_tenant_destination_index');

            // «¿Qué tengo pendiente?», que es la única consulta que no arranca de un almacén: el encargado abre la
            // pantalla para ver lo que espera acción, sin importar de dónde salga.
            $table->index(['tenant_id', 'status', 'created_at'], 'transfers_tenant_status_index');
        });

        // Origen y destino distintos. Es un CHECK y no una validación porque una transferencia a sí misma no es un
        // error de captura recuperable: escribiría dos movimientos que se anulan y un documento que no significa
        // nada. Mejor que no exista.
        DB::statement(<<<'SQL'
            ALTER TABLE `transfers`
            ADD CONSTRAINT `chk_transfers_distinct_warehouses`
            CHECK (`origin_warehouse_id` <> `destination_warehouse_id`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
