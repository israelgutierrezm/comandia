<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `purchase_receipts` — recepción directa de mercancía (D26, §6.2).
 *
 * §6.2: *«proveedor + recepción directa con costos → alimenta inventario e historial de costos»*.
 *
 * ## Sin orden de compra en v1
 *
 * Es la simplificación declarada de D26, y su deuda hay que decirla: **no se puede comparar lo pedido con lo
 * recibido**. La transferencia sí puede —guarda tres cantidades (D187)— y la recepción no, porque no existe el
 * documento del pedido. Cuando aparezca la orden de compra, la línea de recepción tendrá que ganar una cantidad
 * `ordered_quantity` y un enlace al renglón pedido.
 *
 * ## Los tres totales se congelan
 *
 * `subtotal`, `tax_total` y `total` se calculan de las líneas al confirmar y se guardan. Son derivables, y se guardan
 * igual por la misma razón que las dos cifras del conteo (D180): son las cifras con las que se confirmó, y recalcularlas
 * el año que viene desde líneas cuyos impuestos podrían haberse redondeado distinto daría otro número. Un documento que
 * no puede reproducir su propio total no sirve para cuadrar con una factura.
 *
 * ## El IVA se guarda, y que sea costo o no lo decide la configuración
 *
 * El documento registra **siempre** la verdad de la factura: precio sin IVA por línea, tasa por línea, impuesto
 * calculado. Lo que cambia con `purchasing.vat_is_creditable` es el **costo que se manda a Costing**: con IVA
 * acreditable el costo es el neto —el impuesto se recupera— y sin acreditar es el neto más el impuesto, porque
 * entonces el impuesto pagado sí es dinero que no vuelve.
 *
 * Guardar la factura como es y derivar el costo aparte es lo que permite que la configuración cambie sin volver
 * ambiguo el documento. Lo que **no** se recalcula son los costos ya capturados: el historial es inmutable.
 *
 * ## Una recepción confirmada no se edita
 *
 * Se **reversa** con otra recepción que la señala, y la original no se toca — ni siquiera para marcarla como
 * reversada. Es el mismo trato que el kardex: la corrección es un registro nuevo, no una edición del viejo. «¿Está
 * reversada?» es una consulta por el enlace, no una columna que haya que mantener.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            // RESTRICT las dos: una recepción confirmada es evidencia de mercancía que entró y de un costo que quedó en
            // el historial. Si el proveedor o el almacén desaparecieran, esos movimientos se quedarían sin poder decir
            // de quién ni a dónde.
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->string('status', 20);

            // Foliación propia (§7), por la sucursal del almacén. Un almacén central no tiene sucursal, así que la
            // recepción en un central se folía con la sucursal que se congela aquí — la resuelve el servicio, con la
            // misma regla que las transferencias (D189) y la misma restricción.
            $table->foreignId('folio_branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->char('series', 8)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('folio');

            // El folio de SU factura. Es lo que permite cuadrar con el proveedor y detectar una factura capturada dos
            // veces. Nulable porque el puesto del mercado no da factura.
            $table->string('supplier_document_number', 60)->nullable();

            // Cuándo llegó la mercancía. FECHA y no timestamp: una recepción es de un día, y guardar la hora invitaría
            // a ordenar por ella como si significara algo. Se admite pasada porque la factura se captura tarde.
            $table->date('received_at');

            // Los tres totales, congelados al confirmar. `null` mientras es borrador.
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->decimal('tax_total', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();

            // El criterio de IVA con el que se confirmó, congelado. Sin esto, cambiar la configuración volvería
            // inexplicable el costo de las recepciones viejas: se vería el neto y el impuesto, y no cuál de los dos
            // había ido al costo.
            $table->boolean('vat_was_creditable')->nullable();

            // La recepción que ésta reversa. Único: una recepción se reversa UNA vez, y el índice lo impone en lugar de
            // dejarlo a una comprobación que una carrera puede saltarse. Nulable, y MySQL admite tantos `NULL` como
            // haga falta — que es lo que se quiere, porque la mayoría no reversa nada (misma lógica que el RFC, D200).
            $table->foreignId('reverses_receipt_id')
                ->nullable()
                ->constrained('purchase_receipts')
                ->restrictOnDelete();

            $table->foreignId('created_by_membership_id')
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->foreignId('confirmed_by_membership_id')
                ->nullable()
                ->constrained('tenant_memberships')
                ->restrictOnDelete();

            $table->timestamp('confirmed_at')->nullable();

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'folio_branch_id', 'series', 'folio'], 'purchase_receipts_folio_unique');

            $table->unique(['tenant_id', 'reverses_receipt_id'], 'purchase_receipts_reversal_unique');

            // «Las recepciones de este almacén, de la más reciente a la más vieja»: la pantalla de la sección.
            $table->index(['tenant_id', 'warehouse_id', 'received_at'], 'purchase_receipts_tenant_warehouse_index');

            // «Qué le he comprado a este proveedor»: la ficha del proveedor y la negociación.
            $table->index(['tenant_id', 'supplier_id', 'received_at'], 'purchase_receipts_tenant_supplier_index');

            // «Qué está en borrador», que es lo que espera captura o confirmación.
            $table->index(['tenant_id', 'status', 'received_at'], 'purchase_receipts_tenant_status_index');
        });

        // Una factura del mismo proveedor no se captura dos veces. Es el error de captura más caro de todos: duplica
        // existencia, duplica costo y descuadra el inventario contra la realidad sin que nada avise.
        //
        // El número de factura es nulable —el puesto del mercado no da factura— y MySQL admite muchos `NULL`, que es
        // exactamente lo que se quiere aquí.
        DB::statement(<<<'SQL'
            ALTER TABLE `purchase_receipts`
            ADD UNIQUE `purchase_receipts_supplier_document_unique`
                (`tenant_id`, `supplier_id`, `supplier_document_number`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipts');
    }
};
