<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Doble de prueba del scope de tenant.
 *
 * Existe únicamente para que el test estructural pueda demostrar que su
 * detector distingue un modelo acotado de uno sin acotar. El scope real del
 * kernel se construye en la Iteración 1.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), 1);
    }
}
