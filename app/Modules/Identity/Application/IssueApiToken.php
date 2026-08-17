<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use DateTimeInterface;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;

/**
 * Emisión de tokens de API con contexto de tenant (D69).
 *
 * Existe como servicio y no se usa `$user->createToken()` porque ese método no puebla
 * `tenant_id` ni `membership_id`, que son NOT NULL: un token de Comandia sin tenant no
 * es un token válido, es un agujero. Con este servicio como única vía, la columna no
 * puede quedarse vacía por descuido.
 *
 * El token se emite **desde la membresía**, no desde el usuario: es lo que hace
 * evidente que una persona con dos negocios necesita dos tokens.
 */
final class IssueApiToken
{
    /**
     * @param  list<string>  $abilities
     */
    public function issue(
        TenantMembership $membership,
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
    ): NewAccessToken {
        $user = $membership->user;

        if ($user === null) {
            // Una membresía sin credenciales no inicia sesión, así que tampoco emite
            // tokens. El lavaloza en nómina existe en el sistema y no entra a él.
            throw new RuntimeException(
                'No se puede emitir un token para una membresía sin usuario: esa persona no '
                .'tiene credenciales de acceso (ESPECIFICACIÓN_MAESTRA §4.1).'
            );
        }

        if (! $membership->canOperate()) {
            throw new RuntimeException(
                'No se puede emitir un token para una membresía que no está activa.'
            );
        }

        $plainText = Str::random(40);

        $token = $user->tokens()->create([
            'tenant_id' => $membership->tenantId(),
            'membership_id' => $membership->id,
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }
}
