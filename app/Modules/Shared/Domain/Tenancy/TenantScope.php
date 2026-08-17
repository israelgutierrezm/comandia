<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope de tenant. El mecanismo central de aislamiento (ADR-002).
 *
 * Todo modelo de dominio lo lleva, y el test estructural
 * `tests/Architecture/TenantScopeTest.php` falla si a alguno le falta.
 *
 * Dos detalles que no son accidentales:
 *
 * 1. **Lanza excepción si no hay contexto**, en lugar de filtrar por un tenant
 *    inexistente o devolver vacío. Un scope que devuelve cero filas cuando falta
 *    contexto disfraza un error de programación como un resultado legítimo.
 *
 * 2. **Califica la columna con el nombre de la tabla**. Sin `qualifyColumn`, una
 *    consulta con `join` sobre dos tablas que ambas tienen `tenant_id` produce
 *    "Column 'tenant_id' in where clause is ambiguous" — y el momento de
 *    descubrirlo no debe ser el primer reporte con join en producción.
 */
final class TenantScope implements Scope
{
    /**
     * Nombre de la columna de tenant. Igual en toda tabla de dominio (Regla A).
     */
    public const COLUMN = 'tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    public function apply(Builder $builder, Model $model): void
    {
        $builder->where(
            $model->qualifyColumn(self::COLUMN),
            '=',
            $this->context->id(),
        );
    }
}
