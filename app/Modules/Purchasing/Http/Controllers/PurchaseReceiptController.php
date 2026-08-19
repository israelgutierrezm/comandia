<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Application\PurchaseReceiptWorkflow;
use App\Modules\Purchasing\Domain\ReceiptLineDraft;
use App\Modules\Purchasing\Http\Requests\StorePurchaseReceiptRequest;
use App\Modules\Purchasing\Http\Resources\PurchaseReceiptResource;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Shared\Domain\Support\Decimal;
use App\Modules\Shared\Http\Query\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Recepciones de compra (D26, §3.2).
 *
 * ## Dos permisos, y la frontera es lo que mueve inventario
 *
 * Capturar y cancelar un borrador va con `purchasing.receipts.create`; **confirmar y reversar** exigen
 * `purchasing.receipts.confirm`, el permiso que D153 dejó pendiente y que aquí nace con su ruta.
 *
 * La separación es la misma idea que en el conteo físico (D179): capturar la factura es trabajo de quien recibe la
 * mercancía; **aplicarla** mueve existencia y deja un costo en un historial inmutable, y eso decide quien responde por
 * el inventario. Sin la separación, quien captura podría fijar el costo de cualquier artículo — y de ahí salen todos los
 * precios sugeridos y todos los márgenes.
 *
 * ## La conversión a unidad base se hace AQUÍ
 *
 * Es la frontera de la API: hacia fuera se habla de presentaciones y ULIDs, hacia dentro de unidades base. La cantidad
 * convertida se congela en la línea, porque la presentación puede darse de baja mientras el movimiento tiene que seguir
 * cuadrando con el saldo que produjo.
 */
final class PurchaseReceiptController
{
    public function __construct(
        private readonly PurchaseReceiptWorkflow $receipts,
        private readonly Settings $settings,
    ) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, PurchaseReceipt>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['received_at', 'confirmed_at', 'total'],
            // Por el folio de la factura del proveedor: es lo que alguien tiene en la mano al buscar. Es `varchar`, no
            // `ascii_bin`, así que la búsqueda con acentos no lo descarta.
            searchable: ['supplier_document_number'],
            defaultSort: '-received_at',
            dateRanges: ['received' => 'received_at'],
            handledByCaller: ['supplier', 'warehouse', 'only_drafts', 'folio'],
        );

        $receipts = $query->apply($this->baseQuery(), $request);

        // «Qué está en borrador»: lo que espera captura o confirmación, con lo que se abre la pantalla.
        if ($request->boolean('only_drafts')) {
            $receipts->drafts();
        }

        if ($request->filled('folio')) {
            $receipts->where('folio', $request->integer('folio'));
        }

        foreach (['supplier' => 'supplier', 'warehouse' => 'warehouse'] as $param => $relation) {
            if ($request->filled($param)) {
                $receipts->whereHas($relation, fn ($q) => $q->where('ulid', $request->string($param)));
            }
        }

        return PurchaseReceiptResource::collection($receipts->paginate($query->perPage($request)));
    }

    public function show(PurchaseReceipt $purchaseReceipt): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($this->loaded($purchaseReceipt));
    }

    public function store(StorePurchaseReceiptRequest $request): JsonResponse
    {
        $supplier = Supplier::query()->where('ulid', $request->string('supplier_ulid'))->sole();
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();

        $receipt = $this->receipts->draft(
            supplier: $supplier,
            warehouse: $warehouse,
            lines: $this->resolveLines($request),
            receivedAt: CarbonImmutable::parse($request->string('received_at')->toString()),
            supplierDocumentNumber: $request->filled('supplier_document_number')
                ? $request->string('supplier_document_number')->toString()
                : null,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return (new PurchaseReceiptResource($this->loaded($receipt)))
            ->response()
            ->setStatusCode(201);
    }

    public function confirm(PurchaseReceipt $purchaseReceipt): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($this->loaded($this->receipts->confirm($purchaseReceipt)));
    }

    public function cancel(PurchaseReceipt $purchaseReceipt): PurchaseReceiptResource
    {
        return new PurchaseReceiptResource($this->loaded($this->receipts->cancel($purchaseReceipt)));
    }

    public function reverse(Request $request, PurchaseReceipt $purchaseReceipt): JsonResponse
    {
        $reversal = $this->receipts->reverse(
            receipt: $purchaseReceipt,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        // 201: la reversa es un documento NUEVO, no una modificación del original. Devolver el original con otro estado
        // sería mentir sobre lo que acaba de pasar — el original sigue confirmado, y siempre lo estará.
        return (new PurchaseReceiptResource($this->loaded($reversal)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Traduce los renglones de la petición y **congela la conversión a unidad base**.
     *
     * Con presentación, la cantidad se multiplica por `quantity_in_base_unit` de la presentación: «3 cajas de 12 kg» son
     * 36 000 g. Sin presentación, la cantidad ya viene en unidad base.
     *
     * @return list<ReceiptLineDraft>
     */
    private function resolveLines(StorePurchaseReceiptRequest $request): array
    {
        /** @var array<int, array<string, mixed>> $input */
        $input = $request->input('lines');

        $articles = Article::query()
            ->whereIn('ulid', array_column($input, 'article_ulid'))
            ->get()
            ->keyBy('ulid');

        $presentationUlids = array_values(array_filter(array_column($input, 'presentation_ulid')));

        $presentations = $presentationUlids === []
            ? collect()
            : ArticlePurchasePresentation::query()->whereIn('ulid', $presentationUlids)->get()->keyBy('ulid');

        // La tasa del negocio, para los renglones que no la declaran. Se lee una vez y no por renglón: en una factura de
        // doscientas líneas serían doscientas lecturas del mismo valor.
        $defaultRate = Decimal::round((string) $this->settings->get('tax.vat_rate'), 2);

        $drafts = [];

        foreach ($input as $line) {
            $presentation = isset($line['presentation_ulid']) && $line['presentation_ulid'] !== null
                ? $presentations[$line['presentation_ulid']]
                : null;

            $quantity = (string) $line['quantity'];

            $drafts[] = new ReceiptLineDraft(
                article: $articles[$line['article_ulid']],
                presentation: $presentation,
                quantity: $quantity,

                // La conversión, congelada. Si se recalculara al leer, un cambio de catálogo reinterpretaría mercancía
                // que ya entró — y el saldo dejaría de cuadrar con el movimiento que lo produjo.
                quantityInBaseUnit: $presentation === null
                    ? $quantity
                    : Decimal::round(bcmul($quantity, $presentation->quantity_in_base_unit, 6), 4),

                unitPrice: (string) $line['unit_price'],
                taxRate: isset($line['tax_rate']) ? Decimal::round((string) $line['tax_rate'], 2) : $defaultRate,
                lotCode: $line['lot_code'] ?? null,
                expiresAt: $line['expires_at'] ?? null,
            );
        }

        return $drafts;
    }

    /**
     * @return Builder<PurchaseReceipt>
     */
    private function baseQuery(): Builder
    {
        return PurchaseReceipt::query()->with([
            'supplier',
            'warehouse',
            'createdBy.user',
            'confirmedBy.user',
        ]);
    }

    private function loaded(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $receipt->refresh()->load([
            'supplier',
            'warehouse',
            'createdBy.user',
            'confirmedBy.user',
            'reverses',
            'reversal',
            'lines.article.baseUnit',
            'lines.presentation',
        ]);
    }
}
