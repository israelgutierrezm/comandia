<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Configuration\Application\Settings;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Domain\Enums\PurchaseReceiptStatus;
use App\Modules\Purchasing\Domain\Exceptions\PurchaseReceiptInvariantException;
use App\Modules\Purchasing\Domain\ReceiptLineDraft;
use App\Modules\Purchasing\Events\PurchaseReceiptConfirmed;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceiptLine;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Capturar, confirmar y reversar una recepción de compra (D26, §3.2).
 *
 * ## Confirmar es lo único que mueve algo
 *
 * Un borrador es la factura capturada: sirve para cuadrar los totales con el papel **antes** de mover inventario y de
 * dejar un costo en un historial inmutable. Es el estado donde se corrigen los dedazos, y el único.
 *
 * Al confirmar se calculan los totales, se congela el criterio de IVA, y se emite `PurchaseReceiptConfirmed`. Los tres
 * efectos —movimientos de kardex, captura de costo, observación de precio— los aplican sus oyentes, porque
 * `Purchasing` no puede escribir en `Inventory` ni en `Costing` (ADR-001).
 *
 * ## Los totales se calculan de las líneas, no se aceptan del cliente
 *
 * Quien captura teclea cantidad, precio y tasa; el total lo calcula el servidor. Aceptarlo del cliente permitiría una
 * recepción cuyo total no cuadra con sus renglones, y ese documento es imposible de conciliar con la factura — que es lo
 * único que la recepción existe para hacer.
 *
 * Lo que sí se puede es **comparar**: el cliente manda el total de la factura y el servidor dice si coincide. Eso llega
 * cuando exista la UI; por ahora los totales viajan en la respuesta para que se puedan revisar antes de confirmar.
 *
 * ## Reversar y no editar
 *
 * Una recepción confirmada se **reversa** con otra que la señala, y la original no se toca ni para marcarla. Es el mismo
 * trato que el kardex: la corrección es un registro nuevo. «¿Está reversada?» es una consulta por el enlace, no una
 * columna que haya que mantener — y el índice único garantiza que se reverse una sola vez.
 */
final class PurchaseReceiptWorkflow
{
    private const DOCUMENT_TYPE = 'purchase_receipt';

    private const SERIES = 'RC';

    public function __construct(
        private readonly DocumentNumberAllocator $folios,
        private readonly Settings $settings,
        private readonly ContextHolder $context,
    ) {}

    /**
     * Abre un borrador con sus líneas. No mueve nada.
     *
     * @param  list<ReceiptLineDraft>  $lines
     *
     * @throws PurchaseReceiptInvariantException
     */
    public function draft(
        Supplier $supplier,
        Warehouse $warehouse,
        array $lines,
        CarbonImmutable $receivedAt,
        ?string $supplierDocumentNumber = null,
        ?string $notes = null,
        ?PurchaseReceipt $reverses = null,
    ): PurchaseReceipt {
        if (! $supplier->isActive() && $reverses === null) {
            // Un proveedor dado de baja no recibe compras nuevas. La reversa sí procede: deshacer una compra vieja no
            // es comprarle otra vez, y bloquearla dejaría un error sin poder corregirse.
            throw PurchaseReceiptInvariantException::inactiveSupplier($supplier->displayName());
        }

        if ($warehouse->kind === WarehouseKind::Transit) {
            throw PurchaseReceiptInvariantException::transitWarehouse();
        }

        if ($lines === []) {
            throw PurchaseReceiptInvariantException::withoutLines();
        }

        $folioBranchId = $this->resolveFolioBranch($warehouse);
        $membershipId = $this->requireMembership();

        return DB::transaction(function () use (
            $supplier, $warehouse, $lines, $receivedAt, $supplierDocumentNumber,
            $notes, $reverses, $folioBranchId, $membershipId,
        ): PurchaseReceipt {
            // El folio se toma DENTRO de la transacción: el allocator lo exige, porque fuera de ella el lock se libera
            // de inmediato y dos peticiones tomarían el mismo número (§7).
            $folio = $this->folios->next($folioBranchId, self::DOCUMENT_TYPE, self::SERIES);

            $receipt = PurchaseReceipt::create([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'status' => PurchaseReceiptStatus::Draft,
                'folio_branch_id' => $folioBranchId,
                'series' => self::SERIES,
                'folio' => $folio,
                'supplier_document_number' => $supplierDocumentNumber,
                'received_at' => $receivedAt,
                'reverses_receipt_id' => $reverses?->id,
                'created_by_membership_id' => $membershipId,
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                $this->writeLine($receipt, $line);
            }

            return $receipt->refresh();
        });
    }

    /**
     * Confirma: calcula los totales, congela el criterio de IVA y emite el evento.
     *
     * @throws PurchaseReceiptInvariantException
     */
    public function confirm(PurchaseReceipt $receipt): PurchaseReceipt
    {
        $membershipId = $this->requireMembership();

        $confirmed = DB::transaction(function () use ($receipt, $membershipId): PurchaseReceipt {
            $locked = PurchaseReceipt::query()->lockForUpdate()->whereKey($receipt->id)->sole();

            if (! $locked->isOpen()) {
                throw PurchaseReceiptInvariantException::notOpen();
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw PurchaseReceiptInvariantException::withoutLines();
            }

            $subtotal = '0.00';
            $tax = '0.00';

            foreach ($lines as $line) {
                $subtotal = bcadd($subtotal, $line->line_subtotal, 2);
                $tax = bcadd($tax, $line->line_tax, 2);
            }

            $locked->update([
                'status' => PurchaseReceiptStatus::Confirmed,
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'total' => bcadd($subtotal, $tax, 2),

                // El criterio queda CONGELADO. Sin esto, cambiar el ajuste volvería inexplicable el costo de las
                // recepciones viejas: se vería el neto y el impuesto, y no cuál de los dos había ido al costo.
                'vat_was_creditable' => (bool) $this->settings->get('purchasing.vat_is_creditable'),

                'confirmed_by_membership_id' => $membershipId,
                'confirmed_at' => CarbonImmutable::now(),
            ]);

            return $locked;
        });

        // DESPUÉS del commit, y síncrono. Los oyentes registran los movimientos, capturan el costo y dejan la
        // observación de precio; si corrieran dentro de la transacción, un fallo de cualquiera de los tres desharía la
        // confirmación entera — y la mercancía ya está en el estante.
        //
        // Los movimientos llevan llave de idempotencia por línea, así que volver a despachar el evento no duplica nada.
        $this->applyEffects($confirmed->refresh());

        return $confirmed->refresh();
    }

    /**
     * Dispara los tres efectos de la confirmación **sin dejar que su fallo mienta sobre lo que ya pasó**.
     *
     * La confirmación ya está comprometida: la transacción cerró antes de llegar aquí. Si un oyente falla y su excepción
     * sube, la petición responde con un error y quien confirmó **cree que no pasó nada** — cuando la recepción está
     * confirmada, la mercancía en el kardex y el costo capturado. Es la peor mentira que puede decir una interfaz:
     * mucho peor que un fallo, porque invita a volver a intentarlo.
     *
     * Lo encontré confirmando una recepción en el navegador: el tercer oyente rechazó un precio que no cabía en su
     * columna, la pantalla mostró un 422, y la base tenía la recepción confirmada con su movimiento y su costo. La suite
     * no podía verlo — sus tres oyentes siempre tenían éxito.
     *
     * Así que el fallo se registra y **no se propaga**. No es tragarlo en silencio: queda en el log con el documento y
     * el oyente, y el estado incompleto es DETECTABLE desde el propio documento —una línea con cantidad y sin
     * movimiento (`was_applied`) es una confirmación que se quedó a medias—. Y como los tres efectos son idempotentes
     * por llave, volver a despachar el evento repara lo que falte sin duplicar lo que ya está.
     *
     * La alternativa —meter los oyentes en la transacción— es peor y ya estaba descartada: un fallo del tercero
     * desharía la entrada de mercancía que físicamente ya ocurrió.
     */
    private function applyEffects(PurchaseReceipt $receipt): void
    {
        try {
            PurchaseReceiptConfirmed::dispatch($receipt);
        } catch (\Throwable $e) {
            Log::error('Un efecto de la confirmación de recepción falló DESPUÉS del commit.', [
                'purchase_receipt_id' => $receipt->id,
                'folio' => $receipt->folioNumber(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancela un BORRADOR. No hay nada que deshacer.
     *
     * @throws PurchaseReceiptInvariantException
     */
    public function cancel(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return DB::transaction(function () use ($receipt): PurchaseReceipt {
            $locked = PurchaseReceipt::query()->lockForUpdate()->whereKey($receipt->id)->sole();

            if (! $locked->isOpen()) {
                throw PurchaseReceiptInvariantException::confirmedCannotBeCancelled();
            }

            $locked->update(['status' => PurchaseReceiptStatus::Cancelled]);

            return $locked;
        });
    }

    /**
     * Reversa una recepción CONFIRMADA: crea otra que la señala, con las mismas líneas, y la confirma.
     *
     * En un solo paso a propósito. Una reversa en dos —crear y después confirmar— dejaría un borrador de reversa a
     * medias, y el estado «la mercancía ya salió del inventario pero el documento dice que no» es exactamente el que no
     * debe poder existir.
     *
     * @throws PurchaseReceiptInvariantException
     */
    public function reverse(PurchaseReceipt $receipt, ?string $notes = null): PurchaseReceipt
    {
        if (! $receipt->isConfirmed()) {
            throw PurchaseReceiptInvariantException::onlyConfirmedCanBeReversed();
        }

        if ($receipt->isReversal()) {
            // Reversar una reversa sería volver a meter la mercancía, y para eso está capturar una recepción nueva —
            // que además dejaría el precio y la fecha reales en lugar de copiar los de hace un mes.
            throw PurchaseReceiptInvariantException::cannotReverseAReversal();
        }

        if ($receipt->reversal()->exists()) {
            throw PurchaseReceiptInvariantException::alreadyReversed($receipt->folioNumber());
        }

        $lines = $receipt->lines()->with(['article', 'presentation'])->get();

        $drafts = $lines->map(fn (PurchaseReceiptLine $line): ReceiptLineDraft => new ReceiptLineDraft(
            article: $line->article,
            presentation: $line->presentation,
            quantity: $line->quantity,
            quantityInBaseUnit: $line->quantity_in_base_unit,
            unitPrice: $line->unit_price,
            taxRate: $line->tax_rate,

            // El lote NO se copia: la reversa saca del lote que la recepción original creó, y ése ya existe. Copiar el
            // código crearía un lote nuevo con el mismo nombre y la mercancía saldría de la partida equivocada.
            lotCode: null,
            expiresAt: null,
            reversedLotId: $line->lot_id,
        ))->all();

        $reversal = $this->draft(
            supplier: $receipt->supplier,
            warehouse: $receipt->warehouse,
            lines: $drafts,
            receivedAt: CarbonImmutable::now(),

            // El número de factura NO se copia: el índice único lo rechazaría —y con razón, porque no hay dos facturas
            // con el mismo folio. La reversa cita el documento original en sus notas.
            supplierDocumentNumber: null,

            notes: $notes ?? sprintf('Reversa de la recepción %s', $receipt->folioNumber()),
            reverses: $receipt,
        );

        return $this->confirm($reversal);
    }

    /**
     * Escribe una línea con sus tres importes calculados.
     *
     * Los importes se calculan aquí y no se aceptan del cliente: son cantidad × precio y su impuesto, y es exactamente
     * donde se cuela el error de redondeo. Un documento cuyo total no cuadra con sus renglones no se puede conciliar
     * con una factura, que es lo único que la recepción existe para hacer.
     */
    private function writeLine(PurchaseReceipt $receipt, ReceiptLineDraft $line): void
    {
        // A seis decimales y se redondea al final: redondear cada paso sesgaría el total.
        $subtotal = Decimal::round(bcmul($line->quantity, $line->unitPrice, 6), 2);
        $tax = Decimal::round(bcmul($subtotal, bcdiv($line->taxRate, '100', 6), 6), 2);

        PurchaseReceiptLine::create([
            'purchase_receipt_id' => $receipt->id,
            'article_id' => $line->article->id,
            'presentation_id' => $line->presentation?->id,
            'quantity' => $line->quantity,
            'quantity_in_base_unit' => $line->quantityInBaseUnit,
            'unit_price' => $line->unitPrice,
            'tax_rate' => $line->taxRate,
            'line_subtotal' => $subtotal,
            'line_tax' => $tax,
            'line_total' => bcadd($subtotal, $tax, 2),
            'lot_code' => $line->lotCode,
            'expires_at' => $line->expiresAt,

            // En una reversa el lote se conoce desde el principio: es el que creó la recepción original.
            'lot_id' => $line->reversedLotId,
        ]);
    }

    /**
     * La sucursal de la que sale el folio: la del almacén, o **la sucursal activa de quien recibe**.
     *
     * §7 exige foliar por sucursal y un almacén central no tiene ninguna (D11). En las transferencias eso se resolvió con
     * el otro extremo (D189), y aquí no hay otro extremo — así que la primera versión de este método rechazaba las
     * recepciones en almacén central.
     *
     * **Estaba mal, y no por matiz:** recibir en la bodega central es el caso NORMAL de una cadena, precisamente el
     * negocio que más lo necesita. Bloquearlo por un detalle de foliación es la cola moviendo al perro.
     *
     * La sucursal activa de quien recibe es la respuesta correcta y no un parche: el documento lo archiva la sucursal
     * que recibió la mercancía, que es la que va a conciliarlo con la factura. Sale del contexto de la petición, así que
     * no hay nada que preguntarle al cliente.
     *
     * Sólo queda sin foliar el caso en que ni el almacén ni la persona tienen sucursal, que es una membresía con acceso
     * a todas operando sobre un central: ahí sí hay que elegir, y elegir por ella sería inventar el archivo del
     * documento.
     *
     * @throws PurchaseReceiptInvariantException
     */
    private function resolveFolioBranch(Warehouse $warehouse): int
    {
        $branchId = $warehouse->branch_id ?? $this->context->getOrNull()?->activeBranch?->id;

        if ($branchId === null) {
            throw PurchaseReceiptInvariantException::centralWarehouseNeedsBranch();
        }

        return $branchId;
    }

    private function requireMembership(): int
    {
        $membershipId = $this->context->getOrNull()?->membership?->id;

        if ($membershipId === null) {
            throw new \LogicException('Una recepción de compra exige una membresía en contexto: alguien la recibió.');
        }

        return $membershipId;
    }
}
