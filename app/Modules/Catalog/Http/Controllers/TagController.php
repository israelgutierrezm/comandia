<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreTagRequest;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Etiquetas libres del tenant (D19).
 *
 * Es la única entidad del catálogo que **se borra de verdad**: no aparece en ningún documento, así
 * que no hay histórico que preservar y D80 no aplica. El pivote cae por CASCADE.
 *
 * Sin `Resource` propia: una etiqueta es un ULID y un nombre, y una clase de tres líneas por eso sería
 * ceremonia. Se usa `JsonResource` directamente con la forma explícita.
 */
final class TagController
{
    /**
     * @return AnonymousResourceCollection<Collection<int, Tag>>
     */
    public function index(): AnonymousResourceCollection
    {
        $tags = Tag::query()->orderBy('name')->get();

        return JsonResource::collection($tags->map(fn (Tag $tag): array => [
            'ulid' => $tag->ulid,
            'name' => $tag->name,
        ]));
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = Tag::create($request->validated());

        return new JsonResponse([
            'data' => ['ulid' => $tag->ulid, 'name' => $tag->name],
        ], 201);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return new JsonResponse(status: 204);
    }
}
