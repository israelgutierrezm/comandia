<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Http\Resources\PriceChangeResource;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Shared\Http\Query\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historial de precios de un artículo (D15).
 *
 * Vive en `Catalog` —el precio es su dato maestro— y no necesita nada de `Costing`: el snapshot de costeo ya
 * está guardado en cada fila. Es la parte del paso 8 que sí pertenece a este módulo.
 *
 * Paginación por **cursor** y no por página: un cambio masivo de precios escribe una fila por artículo y por
 * sucursal, así que la tabla crece por saltos y no hay "página 500" a la que ir — se investiga hacia atrás
 * (§8).
 */
final class PriceChangeController
{
    public function index(Request $request, Article $article): JsonResponse
    {
        $query = new ListQuery(
            filters: [],
            sortable: ['created_at'],
            // Descendente: quien abre el historial de precios quiere ver el último cambio, no el primero de
            // la historia del artículo.
            //
            // Este controlador tenía a mano el `reorder` con su propio desempate por `id`, resuelto igual que
            // aquí pero por separado. Dos controladores parchando el mismo hueco de `ListQuery` cada uno a su
            // manera es la señal de que el hueco tocaba cerrarlo en `ListQuery`, y ahí está cerrado.
            defaultSort: '-created_at',
            dateRanges: ['changed' => 'created_at'],
        );

        $changes = $query
            ->apply(
                PriceChange::query()
                    ->where('article_id', $article->id)
                    ->with(['actor', 'branch']),
                $request,
            )
            // El orden ya lo puso `ListQuery` —descendente por fecha y desempatado por llave— así que aquí
            // no se reemplaza nada: reemplazarlo volvería a esconder si la declaración de arriba es correcta.
            ->cursorPaginate($query->perPage($request));

        return PriceChangeResource::collection($changes)->response();
    }
}
