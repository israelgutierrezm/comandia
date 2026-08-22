<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Reporting;

/**
 * Un filtro permitido de un reporte (whitelist, §6.7/§8, ADR-006). Lo que no está declarado no se puede filtrar → 422.
 *
 * El motor lo aplica según `$operator`:
 * - `eq` — igualdad contra `$column`. Si `$type` es `ulid`, el motor traduce el ULID a id interno **dentro del tenant**
 *   contra `$ulidTable` (un ULID de otro negocio no se encuentra → no hay fuga).
 * - `in` — lista de valores (mismo tratamiento de ULID por elemento).
 * - `date_range` — el par `{key}_from`/`{key}_to`; el motor convierte los límites, expresados en la fecha local de la
 *   sucursal, a instantes UTC antes de comparar contra `$column` (que guarda UTC).
 */
final readonly class FilterSpec
{
    /**
     * @param  'eq'|'in'|'date_range'  $operator
     * @param  'ulid'|'int'|'string'|'date'  $type
     */
    public function __construct(
        public string $key,
        public string $column,
        public string $operator,
        public string $type,
        public ?string $ulidTable = null,
    ) {}
}
