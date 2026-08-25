<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\ValueObjects\PersonName;
use App\Modules\Shared\Domain\Support\Concerns\HasPublicUlid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Capa 1 de identidad: el usuario global del SaaS
 * (ESPECIFICACIÓN_MAESTRA §4.1).
 *
 * EXCEPCIÓN DECLARADA al global scope de tenant (ADR-002, §1 del diseño): el
 * correo es único en toda la plataforma y una persona puede pertenecer a N tenants
 * independientes. El aislamiento vive en `tenant_memberships`.
 *
 * ## Sobre el trait `HasRoles` de Spatie
 *
 * Se usa, porque asignar roles a un usuario dentro de un tenant es exactamente lo
 * que la configuración `teams = tenant` resuelve, y reimplementar el pivote a mano
 * sería peor. Pero su superficie está **acotada por regla**:
 *
 *   - PERMITIDO: `assignRole()`, `removeRole()`, `syncRoles()` y la relación
 *     `roles()`. Son asignación y consulta de pertenencia.
 *   - PROHIBIDO: `can()`, `hasPermissionTo()`, `hasRole()`, `getAllPermissions()` y
 *     cualquier otra verificación. Todas razonan sobre la SUMA de los roles del
 *     usuario, y aquí opera el rol activo (D9).
 *
 * La prohibición no es un acuerdo verbal: `tests/Architecture/AuthorizationDisciplineTest.php`
 * falla si esos métodos aparecen fuera del servicio de autorización del kernel.
 *
 * Los tokens de Sanctum llevan su propio `tenant_id` y `membership_id` (D69).
 *
 * @property-read int $id
 * @property string $ulid
 * @property string $first_name
 * @property string $paternal_surname
 * @property string|null $maternal_surname
 * @property string $email
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasPublicUlid;

    /**
     * Sólo para asignar roles y leer la relación. Verificar permisos con esta API está
     * prohibido y vigilado por test: ver el bloque de documentación de la clase.
     */
    use HasRoles;

    use Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'first_name',
        'paternal_surname',
        'maternal_surname',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Default de `remember_token` en el modelo, no sólo en la migración.
     *
     * Lo escribe el guard al recordar la sesión, y lo LEE antes de escribirlo. Sin el default, con
     * `preventAccessingMissingAttributes` activo, cerrar sesión lanzaba excepción sobre un usuario recién creado.
     */
    protected $attributes = [
        'remember_token' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',

            // Cifrados en reposo (ARQUITECTURA_MAESTRA §10.4). El cast `encrypted`
            // usa la llave de la aplicación, que es rotable.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',

            'last_login_at' => 'immutable_datetime',
        ];
    }

    /**
     * Nombre global de la persona.
     *
     * Ojo: NO es necesariamente el nombre que se imprime en las comandas de un
     * tenant. La precedencia la fija D66 y la resuelve `MembershipNameResolver`:
     * el perfil de empleado gana sobre esto, porque `users` es global al SaaS y el
     * tenant no puede editarlo.
     */
    public function name(): PersonName
    {
        return new PersonName(
            $this->first_name,
            $this->paternal_surname,
            $this->maternal_surname,
        );
    }

    /**
     * Membresías de esta persona, en todos los tenants a los que pertenece.
     *
     * ATENCIÓN: esta relación es legítimamente cross-tenant y sólo debe usarse en
     * el flujo de identidad —el selector de tenant al iniciar sesión—, que ocurre
     * ANTES de que exista contexto. Usarla desde código de dominio violaría la
     * Regla B de ADR-002.
     *
     * Por eso las consultas de esta relación omiten el global scope: sin hacerlo
     * lanzaría excepción justo en el login, cuando todavía no hay tenant.
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function membershipsAcrossTenants(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScopes();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }
}
