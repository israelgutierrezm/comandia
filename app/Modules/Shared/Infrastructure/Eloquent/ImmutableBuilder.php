<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Eloquent;

use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constructor de consultas que rechaza toda escritura destructiva.
 *
 * Existe porque los eventos de modelo **no cubren el camino del query builder**:
 * `AuditEntry::query()->update([...])` y `->delete()` no disparan `updating` ni
 * `deleting`, así que un `trait` que sólo escuche eventos deja una puerta abierta
 * del tamaño de una consulta en masa. Y una consulta en masa es exactamente la
 * forma en que alguien "arreglaría" una bitácora que no le cuadra.
 *
 * Se cierran las cinco vías de escritura del builder. `insert` y `create` siguen
 * permitidos: estas tablas son append-only, no read-only.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
final class ImmutableBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw ImmutableRecordException::cannotUpdate($this->getModel()::class);
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw ImmutableRecordException::cannotUpdate($this->getModel()::class);
    }

    public function delete(): mixed
    {
        throw ImmutableRecordException::cannotDelete($this->getModel()::class);
    }

    public function forceDelete(): mixed
    {
        throw ImmutableRecordException::cannotDelete($this->getModel()::class);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw ImmutableRecordException::cannotUpdate($this->getModel()::class);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw ImmutableRecordException::cannotUpdate($this->getModel()::class);
    }
}
