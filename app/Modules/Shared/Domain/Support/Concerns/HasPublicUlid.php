<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Identificador público ULID para entidades expuestas por API.
 *
 * Regla del proyecto (ARQUITECTURA_MAESTRA §7): la PK autoincremental es interna
 * y **nunca** se expone al cliente. Un id secuencial visible filtra volumen de
 * negocio —cuántas ventas lleva el tenant, cuándo se dio de alta— y permite
 * enumerar recursos ajenos a base de sumar uno.
 *
 * Detalle de colación que importa: `Str::ulid()` genera base32 de Crockford en
 * **MAYÚSCULAS**, y las columnas `ulid` se declaran `ascii_bin` porque la
 * colación de la base es acento- y caso-insensible (D58). Por eso todo valor que
 * llega del cliente se normaliza a mayúsculas antes de buscar: sin eso,
 * `01hq…` no encontraría la fila guardada como `01HQ…`.
 */
trait HasPublicUlid
{
    public static function bootHasPublicUlid(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('ulid'))) {
                $model->setAttribute('ulid', self::newUlid());
            }
        });
    }

    public static function newUlid(): string
    {
        return Str::upper((string) Str::ulid());
    }

    /**
     * El ULID es la llave de ruta: las URLs de la API exponen el id público.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Normaliza el valor recibido antes de resolver el binding de ruta.
     *
     * Necesario por `ascii_bin`: un ULID en minúsculas en la URL es el mismo
     * identificador y debe encontrar la misma fila.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'ulid') {
            $value = Str::upper((string) $value);
        }

        return $query->where($this->qualifyColumn($field), $value);
    }

    /**
     * Búsqueda por identificador público.
     */
    public static function findByUlid(string $ulid): ?static
    {
        return static::query()
            ->where('ulid', Str::upper($ulid))
            ->first();
    }
}
