<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support\Concerns;

use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Infrastructure\Eloquent\ImmutableBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Marca un modelo como append-only (ARQUITECTURA_MAESTRA §7).
 *
 * Cierra las **tres** vías de escritura destructiva, no sólo la obvia:
 *
 *   1. Eventos de modelo (`updating`, `deleting`) — cubre `$model->save()`,
 *      `$model->delete()`, `Model::destroy()`.
 *   2. El query builder, vía {@see ImmutableBuilder} — cubre
 *      `Model::query()->update()` y `->delete()`, que **no disparan eventos** y
 *      serían la puerta más ancha si sólo se escucharan eventos.
 *   3. `update()` y `delete()` del propio modelo, sobrescritos: sobre un modelo
 *      que no existe en base, Eloquent devuelve `false` sin disparar eventos, y
 *      un `false` silencioso invita a pensar que la operación era legítima.
 *
 * Sin `updated_at`: una tabla append-only no tiene fecha de modificación, así que
 * `$timestamps` se apaga y sólo se escribe `created_at` desde la base
 * (`useCurrent()` en la migración).
 *
 * @phpstan-require-extends Model
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function (Model $model): void {
            throw ImmutableRecordException::cannotUpdate($model::class);
        });

        static::deleting(function (Model $model): void {
            throw ImmutableRecordException::cannotDelete($model::class);
        });

        // `created_at` se escribe desde PHP aunque la migración declare `useCurrent()`.
        //
        // Dos razones. La primera es práctica: sin esto, el modelo recién creado tiene
        // `created_at` en **null** hasta que alguien lo relea, así que quien registre una entrada
        // de auditoría y consulte su fecha —para devolverla en una respuesta, por ejemplo—
        // obtiene null. La segunda es que un solo reloj es más fácil de razonar que dos: con
        // `APP_TIMEZONE=UTC` y la conexión en UTC coinciden, pero depender de que coincidan es
        // frágil.
        //
        // El `useCurrent()` de la migración se queda como red para los demás caminos de
        // escritura: seeders, importaciones, SQL a mano.
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute($model->getCreatedAtColumn() ?? 'created_at'))) {
                $model->setAttribute($model->getCreatedAtColumn() ?? 'created_at', now());
            }
        });
    }

    /**
     * Estas tablas declaran su propio `created_at` y no tienen `updated_at`.
     */
    public function usesTimestamps(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableRecordException::cannotUpdate(static::class);
    }

    public function delete(): bool
    {
        throw ImmutableRecordException::cannotDelete(static::class);
    }

    /**
     * @return ImmutableBuilder<static>
     */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        /** @var QueryBuilder $query */
        return new ImmutableBuilder($query);
    }
}
