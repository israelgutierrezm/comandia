<?php

declare(strict_types=1);

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Ecommerce\Application\ManageMarketplaceMenuMap;
use App\Modules\Ecommerce\Domain\Enums\DeliveryChannelType;
use App\Modules\Ecommerce\Http\Requests\SaveMarketplaceMenuMapRequest;
use App\Modules\Ecommerce\Http\Resources\MarketplaceMenuMapResource;
use App\Modules\Ecommerce\Infrastructure\Models\MarketplaceMenuMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Mapeo del menú de los marketplaces (ADR-015, Fase 2): qué artículo de Comandia es cada ítem del menú de
 * DiDi/Uber/Rappi. Sin mapeo la ingesta rechaza el pedido, así que esto es lo que deja un canal usable de
 * punta a punta. Admin, gateado por `module:Ecommerce` y `ecommerce.store.configure`.
 *
 * La lista no se pagina: la acota el tamaño del menú de un canal (decenas, a lo sumo cientos de ítems).
 */
final class MarketplaceMenuMapController
{
    public function __construct(private readonly ManageMarketplaceMenuMap $manage) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MarketplaceMenuMap::query()->with('article')->orderBy('external_item_id');

        // Único filtro admitido (lista blanca): el canal, contra el catálogo cerrado de canales.
        $channel = DeliveryChannelType::tryFrom((string) $request->query('channel', ''));

        if ($channel !== null) {
            $query->where('channel', $channel->value);
        }

        return MarketplaceMenuMapResource::collection($query->get());
    }

    public function store(SaveMarketplaceMenuMapRequest $request): JsonResponse
    {
        // Por ULID y con el scope de tenant: un artículo de otro negocio no existe aquí (404).
        $article = Article::query()
            ->where('ulid', Str::upper($request->string('article_ulid')->toString()))
            ->firstOrFail();

        $map = $this->manage->save(
            $request->string('channel')->toString(),
            trim($request->string('external_item_id')->toString()),
            $article,
        );

        return new JsonResponse(
            ['data' => new MarketplaceMenuMapResource($map->load('article'))],
            $map->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(MarketplaceMenuMap $marketplaceMenuMap): JsonResponse
    {
        $marketplaceMenuMap->delete();

        return new JsonResponse(status: 204);
    }
}
