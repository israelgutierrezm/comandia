<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token de API con contexto de tenant (D69).
 *
 * El `tenant_id` **viaja con la credencial**, no con la petición: es una llave
 * foránea verificable y no una cadena en `abilities`. Un token no puede cruzar
 * tenants ni por error, y revocar el acceso de una persona a un tenant es borrar
 * sus tokens de ese tenant.
 *
 * NO lleva el global scope de tenant, y es a propósito: Sanctum resuelve el token
 * **antes** de que exista contexto —de hecho el token es el origen del contexto—.
 * Un scope aquí sería una dependencia circular con excepción garantizada en cada
 * petición autenticada por token.
 *
 * Su aislamiento no depende del scope sino de algo más fuerte: el token se
 * encuentra por su hash, que es un secreto de 64 caracteres. Y el middleware de
 * contexto revalida en cada petición que la membresía siga activa, porque una
 * suspensión posterior a la emisión tiene que surtir efecto de inmediato.
 *
 * Por eso figura en la lista de excepciones del test estructural de scopes.
 */
final class PersonalAccessToken extends SanctumToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = [
        'tenant_id',
        'membership_id',
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<TenantMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
