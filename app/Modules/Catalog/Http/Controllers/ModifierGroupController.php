<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Requests\StoreModifierGroupRequest;
use App\Modules\Catalog\Http\Requests\StoreModifierRequest;
use App\Modules\Catalog\Http\Resources\ModifierGroupResource;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Grupos de modificadores y sus opciones (D7).
 *
 * Los grupos son del tenant y se **reutilizan** entre artículos, así que se administran como catálogo propio y
 * no dentro de cada artículo. Editar "Término de la carne" afecta a los ocho cortes que lo usan, y eso hay que
 * poder verlo antes de guardar: por eso el detalle informa a cuántos artículos está asignado.
 */
final class ModifierGroupController
{
    /**
     * @return AnonymousResourceCollection<LengthAwarePaginator<int, ModifierGroup>>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = new ListQuery(
            filters: ['status' => 'status'],
            sortable: ['name', 'created_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        $groups = $query
            ->apply(
                // Con sus opciones: un grupo sin ellas no se puede evaluar, y el listado se usa para elegir.
                ModifierGroup::query()->with(['modifiers' => fn ($q) => $q->orderBy('sort_order')]),
                $request,
            )
            ->paginate($query->perPage($request));

        return ModifierGroupResource::collection($groups);
    }

    public function store(StoreModifierGroupRequest $request): JsonResponse
    {
        $group = ModifierGroup::create($request->validated());

        return (new ModifierGroupResource($group->load('modifiers')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ModifierGroup $modifierGroup): JsonResponse
    {
        $modifierGroup->load(['modifiers' => fn ($q) => $q->orderBy('sort_order')]);

        return (new ModifierGroupResource($modifierGroup))
            ->additional([
                'meta' => [
                    // Cuántos artículos afecta un cambio en las reglas. Es la información que hace responsable
                    // la edición de un grupo compartido, y va en `meta` porque no es parte del recurso.
                    'articles_using' => $modifierGroup->articles()->count(),
                ],
            ])
            ->response();
    }

    public function update(StoreModifierGroupRequest $request, ModifierGroup $modifierGroup): ModifierGroupResource
    {
        $modifierGroup->update($request->validated());

        return new ModifierGroupResource($modifierGroup->refresh()->load('modifiers'));
    }

    /**
     * Baja del grupo: cambio de estado, no borrado (D80).
     *
     * Un grupo asignado a artículos no se borra — el pivote es CASCADE y desaparecería de todos ellos en
     * silencio, dejando platillos que ya no piden el término de la carne.
     */
    public function archive(ModifierGroup $modifierGroup): ModifierGroupResource
    {
        $modifierGroup->update(['status' => 'inactive']);

        return new ModifierGroupResource($modifierGroup->refresh()->load('modifiers'));
    }

    /**
     * Alta de una opción dentro del grupo.
     */
    public function storeModifier(StoreModifierRequest $request, ModifierGroup $modifierGroup): JsonResponse
    {
        // `refresh()` por lo mismo que en las unidades: sin él, `extra_price` sale como llegó —«28»— y en
        // cualquier lectura posterior como «28.00». El mismo importe con dos formas según el endpoint
        // obliga al cliente a normalizar cadenas de dinero, que es justo lo que se evita mandándolas ya
        // formateadas por la columna.
        $modifier = $modifierGroup->modifiers()->create($request->validated())->refresh();

        return new JsonResponse([
            'data' => [
                'ulid' => $modifier->ulid,
                'name' => $modifier->name,
                'extra_price' => $modifier->extra_price,
                'is_paid' => $modifier->isPaid(),
                'sort_order' => $modifier->sort_order,
                'status' => $modifier->status->value,
                'sold_out' => $modifier->sold_out,
            ],
        ], 201);
    }

    public function updateModifier(StoreModifierRequest $request, Modifier $modifier): JsonResponse
    {
        $modifier->update($request->validated());

        return new JsonResponse([
            'data' => [
                'ulid' => $modifier->ulid,
                'name' => $modifier->name,
                'extra_price' => $modifier->extra_price,
                'is_paid' => $modifier->isPaid(),
                'sort_order' => $modifier->sort_order,
                'status' => $modifier->status->value,
                'sold_out' => $modifier->sold_out,
            ],
        ]);
    }

    /**
     * Baja de una opción.
     *
     * No se borra: puede tener receta —y con ella historia de costo— y, desde la Iteración 4, aparecerá en
     * líneas de venta pasadas.
     *
     * Se impide dejar un grupo **obligatorio sin ninguna opción activa**: sería un grupo que exige elegir de
     * una lista vacía, y el POS no podría comandar el platillo. Es la clase de estado que se descubre en hora
     * pico.
     */
    public function archiveModifier(Modifier $modifier): JsonResponse
    {
        $group = $modifier->group;

        if ($group !== null && $group->is_required) {
            $activas = $group->modifiers()
                ->where('status', 'active')
                ->whereKeyNot($modifier->id)
                ->count();

            if ($activas === 0) {
                throw new HttpException(
                    409,
                    'Es la última opción activa de un grupo obligatorio. Agrega otra opción, o deja de marcar '.
                    'el grupo como obligatorio: si no, el punto de venta no podría comandar los platillos que '.
                    'lo usan.'
                );
            }
        }

        $modifier->update(['status' => 'inactive']);

        return new JsonResponse([
            'data' => [
                'ulid' => $modifier->ulid,
                'status' => $modifier->refresh()->status->value,
            ],
        ]);
    }
}
