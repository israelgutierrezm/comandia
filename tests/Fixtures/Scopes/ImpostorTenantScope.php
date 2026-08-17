<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Impostor deliberado: un scope que *parece* el de tenant.
 *
 * Existe para demostrar que el candado estructural compara por FQCN y no por
 * nombre corto. Antes del endurecimiento de la Iteración 1, una clase así habría
 * pasado el test —y con ella, cualquier scope casero que filtrara por otra cosa,
 * o por nada—.
 *
 * No debe usarse en ningún otro lugar.
 */
final class ImpostorTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // A propósito no filtra nada: es el fallo que el candado debe cazar.
    }
}
