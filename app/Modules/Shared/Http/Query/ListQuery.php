<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Filtros, orden y búsqueda con WHITELIST por endpoint (ARQUITECTURA_MAESTRA §8).
 *
 * "Nunca filtros libres" no es una preferencia de estilo. Un endpoint que acepta cualquier
 * columna en el `where` o en el `order by` es un motor de consultas expuesto a internet: deja
 * inferir datos por diferencia de resultados, permite ordenar por columnas que no debería
 * revelar, y basta un `order by` sobre una columna sin índice para degradar la base.
 *
 * Lo que no está declarado **no existe**: un filtro desconocido produce 422 y no se ignora en
 * silencio. Ignorarlo devolvería una lista completa a alguien que cree estar viendo una
 * filtrada — el peor resultado posible, porque parece correcto.
 */
final readonly class ListQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array<string, string>  $filters  parámetro público => columna real
     * @param  list<string>  $sortable  parámetros públicos ordenables
     * @param  list<string>  $searchable  columnas del `search`
     */
    public function __construct(
        private array $filters,
        private array $sortable,
        private array $searchable = [],
        private string $defaultSort = 'name',
    ) {}

    /**
     * Aplica lo que venga en la petición, validado contra la whitelist.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @throws ValidationException
     */
    public function apply(Builder $query, Request $request): Builder
    {
        $this->rejectUnknownFilters($request);

        foreach ($this->filters as $parameter => $column) {
            if ($request->has($parameter)) {
                $query->where($column, $request->string($parameter)->toString());
            }
        }

        $this->applySearch($query, $request);

        return $this->applySort($query, $request);
    }

    public function perPage(Request $request): int
    {
        $requested = (int) $request->integer('per_page', self::DEFAULT_PER_PAGE);

        // Se acota en silencio en lugar de rechazar: pedir 5,000 registros es un error del
        // cliente que no cambia el significado de la respuesta, y devolverle 422 por eso sería
        // ruido. Lo que sí sería grave es servirlos.
        return max(1, min($requested, self::MAX_PER_PAGE));
    }

    /**
     * @throws ValidationException
     */
    private function rejectUnknownFilters(Request $request): void
    {
        $reserved = ['page', 'per_page', 'sort', 'search'];
        $allowed = [...array_keys($this->filters), ...$reserved];

        $unknown = array_diff(array_keys($request->query()), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => [sprintf(
                    'Estos filtros no están permitidos en este listado: %s. Permitidos: %s.',
                    implode(', ', $unknown),
                    implode(', ', array_keys($this->filters)) ?: 'ninguno',
                )],
            ]);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = $request->string('search')->trim()->toString();

        if ($term === '' || $this->searchable === []) {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            foreach ($this->searchable as $column) {
                // `like` sobre una base con colación acento-insensible (D58): buscar "cafe"
                // encuentra "Café" sin que haya que normalizar nada en PHP.
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @throws ValidationException
     */
    private function applySort(Builder $query, Request $request): Builder
    {
        $requested = $request->string('sort')->toString();

        if ($requested === '') {
            return $query->orderBy($this->defaultSort);
        }

        $descending = str_starts_with($requested, '-');
        $column = ltrim($requested, '-');

        if (! in_array($column, $this->sortable, strict: true)) {
            throw ValidationException::withMessages([
                'sort' => [sprintf(
                    'No se puede ordenar por «%s». Columnas ordenables: %s.',
                    $column,
                    implode(', ', $this->sortable),
                )],
            ]);
        }

        return $query->orderBy($column, $descending ? 'desc' : 'asc');
    }
}
