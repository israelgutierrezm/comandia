<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Reporting;

use Illuminate\Database\Eloquent\Builder;

/**
 * La definición declarativa de un reporte (ADR-006, ADR-007). La declara el módulo DUEÑO del dato y la registra en el
 * {@see ReportRegistry} del kernel; el motor de `Reporting` la ejecuta.
 *
 * ## No ejecuta ni aplica scoping
 *
 * `baseQuery()` devuelve una consulta A MEDIO CONSTRUIR sobre la tabla del propio dueño (Eloquent, para heredar el global
 * scope de tenant). El MOTOR le inyecta el alcance de sucursal, valida y aplica los filtros/columnas/agrupaciones contra
 * la whitelist, agrega y ejecuta (regla 4 de ADR-006: el scoping es del motor, no de la definición). Así una definición
 * jamás puede, por olvido, exponer datos de otro tenant o de una sucursal fuera de alcance.
 */
interface ReportDefinition
{
    /** Slug único del reporte, p. ej. `sales.by_article`. */
    public function key(): string;

    /** Nombre legible para el catálogo/menú. */
    public function label(): string;

    /** Un permiso de DOMINIO YA EXISTENTE (catálogo cerrado); el motor lo verifica y el widget de dashboard lo hereda. */
    public function permission(): string;

    /** La consulta base: Eloquent sobre la tabla del dueño, con sus joins internos. Sin tenant ni sucursal (los pone el motor). */
    public function baseQuery(): Builder;

    /** Columna calificada que sostiene el `branch_id`, para el scoping de sucursal del rol activo. */
    public function branchColumn(): string;

    /** Columna calificada de fecha (UTC) para el filtro de rango, o null si el reporte no filtra por fecha. */
    public function dateColumn(): ?string;

    /** @return array<string, Dimension> */
    public function dimensions(): array;

    /** @return array<string, Measure> */
    public function measures(): array;

    /** @return array<string, FilterSpec> */
    public function filters(): array;

    /** @return list<string> claves de dimensiones admitidas como `group by` */
    public function groupings(): array;

    /** @return list<string> agrupación por omisión cuando el cliente no pide ninguna */
    public function defaultGrouping(): array;
}
