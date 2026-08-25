<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Models;

use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * El super administrador de la PLATAFORMA (el operador del SaaS), no de un negocio.
 *
 * Vive en su propia tabla y se autentica con su propio guard (`platform`), totalmente aislado del personal de los
 * negocios (`users`): un super admin no es —ni puede iniciar sesión como— un usuario de ningún negocio, igual que el
 * «central»/landlord de otros SaaS. Por eso NO lleva `tenant_id` ni global scope de tenant: es una identidad de
 * plataforma, no un modelo de dominio (excepción declarada al ADR-002, la misma que `users`).
 */
final class PlatformAdmin extends Authenticatable
{
    use HasPublicUlid;
    use Notifiable;

    protected $table = 'platform_admins';

    /**
     * Default de `remember_token`: el guard lo escribe al recordar la sesión y lo LEE antes de escribirlo. Sin el
     * default, con `preventAccessingMissingAttributes` activo, cerrar sesión lanzaba excepción sobre un admin recién
     * creado (el mismo caso que `User`).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['remember_token' => null];

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
