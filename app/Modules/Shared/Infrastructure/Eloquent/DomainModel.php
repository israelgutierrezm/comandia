<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Eloquent;

use App\Modules\Shared\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de todo modelo de dominio de Comandia.
 *
 * Extender de aquí no es una comodidad: es lo que garantiza que el modelo nazca
 * acotado por tenant (ADR-002). Los únicos modelos que NO deben extender de esta
 * clase son los tres globales al SaaS declarados como excepción —`Tenant`,
 * `User` y `Permission`—, y cada uno figura en la lista de excepciones del test
 * estructural con su justificación escrita.
 *
 * Sin `SoftDeletes` a propósito (D80): el ciclo de vida de las entidades del
 * kernel se modela con `status`. Hay documentos históricos apuntando a sucursales
 * y almacenes, y un `deleted_at` conviviendo con índices únicos que ya no
 * distinguen es una trampa que se descubre tarde.
 */
abstract class DomainModel extends Model
{
    use BelongsToTenant;

    /**
     * Sin asignación masiva abierta (ARQUITECTURA_MAESTRA §10.7). Cada modelo
     * declara su propio `$fillable`; heredar `$guarded = []` sería justo lo
     * contrario de lo que pide la regla.
     */
    protected $guarded = ['id', 'ulid', 'tenant_id'];
}
