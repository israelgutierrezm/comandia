<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Query;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
    /**
     * Búsqueda por `like` sobre las columnas declaradas.
     *
     * ## Por qué se descartan columnas cuando el término lleva acentos
     *
     * Buscar «azúcar» **reventaba con un 500**. MySQL 8 rechaza comparar una columna `ascii_bin` con
     * un parámetro que no es ASCII: «Conversion from collation utf8mb4_0900_ai_ci into ascii_bin
     * impossible for parameter» (error 3988). Y los códigos son `ascii_bin` a propósito (D58), para
     * que `Kg` y `kg` sean valores distintos.
     *
     * Así que en un SaaS mexicano, buscar «azúcar», «jalapeño» o «piña» en cualquier listado con
     * columna de código devolvía un error del servidor. Ninguna prueba lo vio porque todas buscaban
     * palabras sin acentos; lo encontró el navegador, escribiendo lo que un usuario escribe.
     *
     * Descartar esas columnas **no pierde resultados**: una columna ASCII no puede contener «azúcar»,
     * así que la comparación que se omite nunca habría coincidido. Lo que se omite es un error.
     *
     * La colación se lee del ESQUEMA y no de una lista declarada aquí: una lista tendría que
     * actualizarse en cada migración y el día que alguien la olvidara volvería el 500. El esquema no
     * puede desincronizarse de sí mismo.
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $term = $request->string('search')->trim()->toString();

        if ($term === '') {
            return;
        }

        // Buscar en un listado que no declara columnas buscables se RECHAZA, no se ignora. Ignorarlo
        // devolvía la lista completa a quien había buscado algo concreto, y ése es el peor resultado posible
        // porque parece correcto: el cliente cree estar viendo coincidencias y está viendo todo.
        //
        // Es la misma regla que los filtros (§8): lo que no está declarado no existe.
        if ($this->searchable === []) {
            throw ValidationException::withMessages([
                'search' => ['Este listado no admite búsqueda por texto.'],
            ]);
        }

        $columns = $this->searchableFor($query, $term);

        if ($columns === []) {
            // Todas las columnas buscables son ASCII y el término no lo es: no hay nada que pueda
            // coincidir. Se fuerza el conjunto vacío en lugar de no filtrar, porque devolver la lista
            // completa a quien buscó algo es el peor resultado — parece correcto.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $query) use ($columns, $term): void {
            foreach ($columns as $column) {
                // `like` sobre una base con colación acento-insensible (D58): buscar "cafe"
                // encuentra "Café" sin que haya que normalizar nada en PHP.
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * Las columnas buscables que pueden comparar con este término.
     *
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    private function searchableFor(Builder $query, string $term): array
    {
        // Término ASCII: sirven todas, y no hace falta preguntarle nada al esquema.
        if (mb_check_encoding($term, 'ASCII')) {
            return $this->searchable;
        }

        $table = $query->getModel()->getTable();

        return array_values(array_filter(
            $this->searchable,
            fn (string $column): bool => ! self::isAsciiColumn($table, $column),
        ));
    }

    /**
     * ¿La columna está en una colación que sólo admite ASCII?
     *
     * El resultado se memoriza por proceso: es metadato del esquema y no cambia entre peticiones, y
     * sin memorizar sería una consulta de catálogo por búsqueda con acento.
     */
    private static function isAsciiColumn(string $table, string $column): bool
    {
        static $cache = [];

        if (! array_key_exists($table, $cache)) {
            $collations = [];

            foreach (Schema::getColumns($table) as $definition) {
                $collations[$definition['name']] = $definition['collation'] ?? null;
            }

            $cache[$table] = $collations;
        }

        return str_starts_with((string) ($cache[$table][$column] ?? ''), 'ascii');
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
        // El orden POR OMISIÓN admite el mismo prefijo `-` que el que pide el cliente, y hacía falta:
        // sin esto, `defaultSort: '-started_at'` producía un `order by \`-started_at\`` y MySQL contestaba
        // «Unknown column». O sea que la única forma de declarar un listado descendente por omisión era
        // un 500 en producción, y quien lo intentara no tenía manera de sospecharlo leyendo la firma.
        //
        // Y no es un caso hipotético: la bitácora de auditoría y el historial de precios abrían por la
        // entrada MÁS VIEJA, porque descendente no se podía expresar. Un registro histórico cuya primera
        // página es la de hace un año no contesta la pregunta por la que se abre.
        $requested = $request->string('sort')->toString();

        if ($requested === '') {
            $requested = $this->defaultSort;
        }

        $descending = str_starts_with($requested, '-');
        $column = ltrim($requested, '-');

        // El orden por omisión no pasa por la whitelist: lo declara el controlador, no el cliente, y
        // exigirle estar en `sortable` obligaría a publicar como ordenable toda columna que sólo sirve de
        // orden natural.
        if ($requested === $this->defaultSort) {
            return $this->orderDeterministically($query, $column, $descending);
        }

        if (! in_array($column, $this->sortable, strict: true)) {
            throw ValidationException::withMessages([
                'sort' => [sprintf(
                    'No se puede ordenar por «%s». Columnas ordenables: %s.',
                    $column,
                    implode(', ', $this->sortable),
                )],
            ]);
        }

        return $this->orderDeterministically($query, $column, $descending);
    }

    /**
     * Ordena por la columna pedida **y desempata por la llave primaria**.
     *
     * Sin el desempate, el orden de dos filas con el mismo valor lo decide MySQL y puede cambiar entre
     * consultas idénticas. En un listado paginado eso no es una molestia estética: una fila puede aparecer en
     * dos páginas o en ninguna, porque la página 2 se calcula con un orden distinto del de la página 1.
     *
     * Y no es raro. Se descubrió con dos conteos abiertos el mismo segundo —`started_at` tiene precisión de
     * segundo— pero pasa igual con dos artículos del mismo nombre o dos movimientos del mismo instante, que
     * es exactamente lo que hace un POS.
     *
     * La llave primaria desempata bien porque es única y monótona: para filas creadas en el mismo segundo,
     * ordenar por `id` es ordenar por el momento real de creación.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function orderDeterministically(Builder $query, string $column, bool $descending): Builder
    {
        $direction = $descending ? 'desc' : 'asc';
        $key = $query->getModel()->getQualifiedKeyName();

        $query->orderBy($column, $direction);

        // Si ya se ordena por la llave, añadirla otra vez no aporta nada.
        return $column === $query->getModel()->getKeyName() || $column === $key
            ? $query
            : $query->orderBy($key, $direction);
    }
}
