<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Inventory\Application\RegisterWaste;
use App\Modules\Inventory\Http\Concerns\AssertsWarehouseScope;
use App\Modules\Inventory\Http\Requests\StoreWasteReasonRequest;
use App\Modules\Inventory\Http\Requests\StoreWasteRequest;
use App\Modules\Inventory\Http\Requests\UpdateWasteReasonRequest;
use App\Modules\Inventory\Http\Resources\StockMovementResource;
use App\Modules\Inventory\Http\Resources\WasteReasonResource;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Mermas y su catálogo de motivos (D27, §6.2).
 *
 * ## El catálogo y el registro comparten permiso, a propósito
 *
 * `inventory.waste.create` cubre las dos cosas. El catálogo cerrado no tiene un permiso para administrar motivos, y
 * **no se agrega uno**: quien registra mermas necesita poder crear el motivo que le falta en el momento en que le
 * falta. Obligarlo a pedirle a un gerente que dé de alta «se cayó al piso» acabaría con todas las mermas
 * registradas bajo un motivo genérico, que es justo lo que D27 existe para evitar.
 *
 * Es la misma lógica que las etiquetas del catálogo (D19): vocabulario libre del negocio, administrado por quien lo
 * usa. Lo que sí está separado —y con razón— es **autorizar** una merma sobre el umbral.
 */
final class WasteController
{
    use AssertsWarehouseScope;

    public function __construct(private readonly RegisterWaste $waste) {}

    /**
     * @return AnonymousResourceCollection<Collection<int, WasteReason>>
     */
    public function reasons(Request $request): AnonymousResourceCollection
    {
        // Sin paginar: un negocio tiene una docena de motivos. Paginarlos obligaría a recorrer páginas para llenar
        // un selector.
        $reasons = WasteReason::query()
            ->when(
                $request->boolean('only_active', true),
                fn ($query) => $query->active(),
            )
            ->orderBy('name')
            ->get();

        return WasteReasonResource::collection($reasons);
    }

    public function storeReason(StoreWasteReasonRequest $request): JsonResponse
    {
        $reason = WasteReason::create($request->validated());

        return (new WasteReasonResource($reason->refresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function updateReason(UpdateWasteReasonRequest $request, WasteReason $wasteReason): WasteReasonResource
    {
        $wasteReason->update($request->validated());

        return new WasteReasonResource($wasteReason->refresh());
    }

    /**
     * Registra una merma.
     *
     * Devuelve una **lista** de movimientos por lo mismo que la salida manual: si el artículo lleva lotes, FEFO
     * parte la merma y cada renglón dice de qué partida física se perdió — que es exactamente lo que se necesita
     * cuando la causa es un lote defectuoso.
     */
    public function store(StoreWasteRequest $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('ulid', $request->string('warehouse_ulid'))->sole();
        $article = Article::query()->where('ulid', $request->string('article_ulid'))->sole();
        $reason = WasteReason::query()->where('ulid', $request->string('waste_reason_ulid'))->sole();

        $this->assertWarehouseInScope($warehouse);

        $movements = $this->waste->register(
            warehouse: $warehouse,
            article: $article,
            reason: $reason,
            quantity: $request->string('quantity')->toString(),

            lot: $request->filled('lot_ulid')
                ? ArticleLot::query()->where('ulid', $request->string('lot_ulid'))->sole()
                : null,

            // La concesión de PIN, si la merma pasa el umbral. Sin ella el servicio lanza y la respuesta es 409
            // con `authorization_required`, que es lo que la UI usa para abrir el diálogo del PIN.
            authorizationToken: $request->filled('authorization_token')
                ? $request->string('authorization_token')->toString()
                : null,

            occurredAt: $request->filled('occurred_at')
                ? CarbonImmutable::parse($request->string('occurred_at')->toString())
                : null,

            notes: $request->filled('notes') ? $request->string('notes')->toString() : null,
        );

        $loaded = collect($movements)->each(
            fn ($movement) => $movement->load(['article.baseUnit', 'warehouse', 'lot', 'actor', 'wasteReason'])
        );

        return StockMovementResource::collection($loaded)
            ->response()
            ->setStatusCode(201);
    }
}
