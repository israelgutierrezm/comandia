<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\TransferWorkflow;
use App\Modules\Inventory\Http\Concerns\AssertsWarehouseScope;
use App\Modules\Inventory\Http\Requests\StoreTransferRequest;
use App\Modules\Inventory\Http\Requests\TransferQuantitiesRequest;
use App\Modules\Inventory\Http\Resources\TransferResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Transferencias entre almacenes (D25, §6.2).
 *
 * ## Qué almacén se comprueba en cada paso, y por qué no es siempre el mismo
 *
 * Cada paso lo da quien tiene la mercancía delante:
 *
 *   - **Solicitar** lo hace el destino —es quien necesita— pero se comprueba el alcance sobre los dos: pedirle
 *     mercancía a una sucursal a la que no tienes acceso es igual de indebido que sacársela.
 *   - **Enviar** exige alcance sobre el ORIGEN: la mercancía sale de ahí.
 *   - **Recibir** exige alcance sobre el DESTINO: llega ahí.
 *
 * Comprobar siempre el origen dejaría al destino sin poder recibir lo que le mandaron, que es la mitad del flujo.
 */
final class TransferController
{
    use AssertsWarehouseScope;

    public function __construct(private readonly TransferWorkflow $transfers) {}

    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, Transfer>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['created_at'],
            // Sin búsqueda de texto: una transferencia se busca por folio, y el folio es un filtro exacto, no un
            // `like`. Declararlo vacío hace que `?search=` se rechace en lugar de ignorarse (D182).
            searchable: [],
            defaultSort: '-created_at',
            dateRanges: ['created' => 'created_at'],
            handledByCaller: ['warehouse', 'origin', 'destination', 'only_open', 'folio'],
        );

        $transfers = $query->apply($this->baseQuery(), $request);

        // «Lo que espera acción de alguien», que es con lo que se abre la pantalla: una transferencia recibida hace
        // tres meses no le interesa a nadie que esté trabajando.
        if ($request->boolean('only_open')) {
            $transfers->open();
        }

        if ($request->filled('folio')) {
            $transfers->where('folio', $request->integer('folio'));
        }

        // `warehouse` sin dirección: «todo lo de este almacén», entre y salga. Es la pregunta del encargado, que no
        // piensa en dos listas.
        if ($request->filled('warehouse')) {
            $id = $this->warehouseId($request->string('warehouse')->toString());

            $transfers->where(fn ($q) => $q
                ->where('origin_warehouse_id', $id)
                ->orWhere('destination_warehouse_id', $id));
        }

        foreach (['origin' => 'origin_warehouse_id', 'destination' => 'destination_warehouse_id'] as $param => $column) {
            if ($request->filled($param)) {
                $transfers->where($column, $this->warehouseId($request->string($param)->toString()));
            }
        }

        return TransferResource::collection($transfers->paginate($query->perPage($request)));
    }

    public function show(Transfer $transfer): TransferResource
    {
        return new TransferResource($this->loaded($transfer));
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $origin = Warehouse::query()->where('ulid', $request->string('origin_warehouse_ulid'))->sole();
        $destination = Warehouse::query()->where('ulid', $request->string('destination_warehouse_ulid'))->sole();

        $this->assertWarehouseInScope($origin);
        $this->assertWarehouseInScope($destination);

        /** @var array<int, array{article_ulid: string, lot_ulid?: string|null, quantity: string}> $input */
        $input = $request->input('lines');

        [$articles, $lots] = $this->resolveLineReferences($input);

        $lines = array_map(fn (array $line): array => [
            'article' => $articles[$line['article_ulid']],
            'lot' => isset($line['lot_ulid']) && $line['lot_ulid'] !== null ? $lots[$line['lot_ulid']] : null,
            'quantity' => (string) $line['quantity'],
        ], $input);

        $transfer = $this->transfers->request(
            origin: $origin,
            destination: $destination,
            lines: $lines,
            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        return (new TransferResource($this->loaded($transfer)))
            ->response()
            ->setStatusCode(201);
    }

    public function authorize(Transfer $transfer): TransferResource
    {
        $this->assertWarehouseInScope($transfer->originWarehouse);

        return new TransferResource($this->loaded($this->transfers->authorize($transfer)));
    }

    public function prepare(Transfer $transfer): TransferResource
    {
        // El origen: preparar es juntar la mercancía en el almacén de donde sale.
        $this->assertWarehouseInScope($transfer->originWarehouse);

        return new TransferResource($this->loaded($this->transfers->prepare($transfer)));
    }

    public function ship(TransferQuantitiesRequest $request, Transfer $transfer): TransferResource
    {
        $this->assertWarehouseInScope($transfer->originWarehouse);

        $shipped = $this->quantitiesByLineId($request, $transfer);

        return new TransferResource($this->loaded($this->transfers->ship($transfer, $shipped)));
    }

    public function receive(TransferQuantitiesRequest $request, Transfer $transfer): TransferResource
    {
        // El DESTINO, no el origen: recibe quien tiene la mercancía delante al bajarla del camión.
        $this->assertWarehouseInScope($transfer->destinationWarehouse);

        $received = $this->quantitiesByLineId($request, $transfer);

        return new TransferResource($this->loaded($this->transfers->receive($transfer, $received)));
    }

    public function cancel(Transfer $transfer): TransferResource
    {
        $this->assertWarehouseInScope($transfer->originWarehouse);

        return new TransferResource($this->loaded($this->transfers->cancel($transfer)));
    }

    /**
     * Traduce los renglones de la petición —artículo y lote por ULID— a cantidades por id de línea.
     *
     * La traducción vive aquí y no en el servicio porque es la frontera de la API: hacia fuera se habla de ULIDs
     * (§7), y hacia dentro de las líneas que ya existen. Un renglón que no corresponde a ninguna línea se ignora
     * en silencio a propósito — la alternativa sería un 422 por mandar de más en una hoja de embarque, que es una
     * fricción sin beneficio: lo que no está en el documento no se puede mover de todos modos.
     *
     * @return array<int, string>
     */
    private function quantitiesByLineId(TransferQuantitiesRequest $request, Transfer $transfer): array
    {
        /** @var array<int, array{article_ulid: string, lot_ulid?: string|null, quantity: string}> $input */
        $input = $request->input('lines');

        $lines = $transfer->lines()->with(['article', 'lot'])->get();

        $byKey = [];

        foreach ($lines as $line) {
            $byKey[$line->article->ulid.'|'.($line->lot?->ulid ?? '')] = $line->id;
        }

        $quantities = [];

        foreach ($input as $row) {
            $key = $row['article_ulid'].'|'.($row['lot_ulid'] ?? '');

            if (isset($byKey[$key])) {
                $quantities[$byKey[$key]] = (string) $row['quantity'];
            }
        }

        return $quantities;
    }

    /**
     * @param  array<int, array{article_ulid: string, lot_ulid?: string|null}>  $input
     * @return array{0: Collection<string, Article>, 1: Collection<string, ArticleLot>}
     */
    private function resolveLineReferences(array $input): array
    {
        $articles = Article::query()
            ->whereIn('ulid', array_column($input, 'article_ulid'))
            ->get()
            ->keyBy('ulid');

        $lotUlids = array_values(array_filter(array_column($input, 'lot_ulid')));

        $lots = $lotUlids === []
            ? collect()
            : ArticleLot::query()->whereIn('ulid', $lotUlids)->get()->keyBy('ulid');

        return [$articles, $lots];
    }

    private function warehouseId(string $ulid): int
    {
        return Warehouse::query()->where('ulid', $ulid)->sole()->id;
    }

    /**
     * @return Builder<Transfer>
     */
    private function baseQuery(): Builder
    {
        return Transfer::query()->with([
            'originWarehouse',
            'destinationWarehouse',
            'requestedBy.user',
            'authorizedBy.user',
            'preparedBy.user',
            'shippedBy.user',
            'receivedBy.user',
        ]);
    }

    private function loaded(Transfer $transfer): Transfer
    {
        return $transfer->refresh()->load([
            'originWarehouse',
            'destinationWarehouse',
            'requestedBy.user',
            'authorizedBy.user',
            'preparedBy.user',
            'shippedBy.user',
            'receivedBy.user',
            'lines.article.baseUnit',
            'lines.lot',
        ]);
    }
}
