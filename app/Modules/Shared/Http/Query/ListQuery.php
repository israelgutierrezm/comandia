<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Query;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
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
     * @param  array<string, string>  $dateRanges  prefijo público => columna real
     * @param  list<string>  $handledByCaller  parámetros permitidos que el controlador aplica a mano
     */
    public function __construct(
        private array $filters,
        private array $sortable,
        private array $searchable = [],
        private string $defaultSort = 'name',
        private array $dateRanges = [],
        private array $handledByCaller = [],
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

        $this->applyDateRanges($query, $request);
        $this->applySearch($query, $request);

        return $this->applySort($query, $request);
    }

    /**
     * Rangos de fecha, declarados igual que los demás filtros.
     *
     * Un prefijo `created` habilita `created_from` y `created_to`. Se declaran en la whitelist
     * como todo lo demás: "sin filtros libres" no admite una excepción para las fechas, que son
     * justo por donde se piden listados enormes.
     *
     * Se valida que sean fechas y que el rango no esté invertido, en lugar de dejar que MySQL
     * devuelva un conjunto vacío: un rango invertido casi siempre es un error del cliente, y una
     * lista vacía se interpreta como "no hay datos".
     *
     * @param  Builder<Model>  $query
     *
     * @throws ValidationException
     */
    private function applyDateRanges(Builder $query, Request $request): void
    {
        foreach ($this->dateRanges as $prefix => $column) {
            $from = $this->parseDate($request, "{$prefix}_from");
            $to = $this->parseDate($request, "{$prefix}_to");

            if ($from !== null && $to !== null && $from->greaterThan($to)) {
                throw ValidationException::withMessages([
                    "{$prefix}_from" => ['La fecha inicial no puede ser posterior a la final.'],
                ]);
            }

            if ($from !== null) {
                $query->where($column, '>=', $from);
            }

            if ($to !== null) {
                // Fin de día: quien filtra "hasta el 5 de marzo" espera incluir ese día completo,
                // no cortar a la medianoche de su inicio.
                $query->where($column, '<=', $to->endOfDay());
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function parseDate(Request $request, string $parameter): ?CarbonImmutable
    {
        $raw = $request->string($parameter)->trim()->toString();

        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            throw ValidationException::withMessages([
                $parameter => ['La fecha no tiene un formato válido. Usa AAAA-MM-DD.'],
            ]);
        }
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
        $reserved = ['page', 'per_page', 'sort', 'search', 'cursor'];

        $rangeParameters = [];

        foreach (array_keys($this->dateRanges) as $prefix) {
            $rangeParameters[] = "{$prefix}_from";
            $rangeParameters[] = "{$prefix}_to";
        }

        // `handledByCaller` son filtros que el controlador traduce antes de aplicarlos —por
        // ejemplo un ULID público a la llave interna que la API no expone (§7)—. Se declaran aquí
        // igual que los demás: siguen estando en la whitelist, sólo que quien los aplica es otro.
        // Sin esta lista, un controlador que necesitara traducir un filtro tendría que dejar de
        // usar la whitelist, y ahí es donde se pierden los candados.
        $allowed = [
            ...array_keys($this->filters),
            ...$rangeParameters,
            ...$this->handledByCaller,
            ...$reserved,
        ];

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
