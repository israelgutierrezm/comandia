<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\ValueObjects\PersonName;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Resuelve el nombre de una persona dentro de un tenant (D66).
 *
 * ## La regla, en un solo lugar
 *
 * El nombre tiene dos orígenes posibles y la precedencia es:
 *
 *   1. `employee_profiles` (nombre legal), si existe perfil.
 *   2. `users` (nombre global), si la membresía tiene credenciales.
 *   3. Nada — y eso viola el invariante I1, así que se lanza excepción.
 *
 * ## Por qué el perfil de empleado gana sobre el usuario
 *
 * `users` es una tabla **global del SaaS** y el tenant no puede editarla. Con la
 * precedencia inversa, alguien que escribiera su nombre como "j ruiz" en su perfil
 * global vería eso impreso en las comandas de todos los restaurantes donde
 * trabaja, y ninguno podría corregirlo. Con esta precedencia el tenant recupera el
 * control creando el perfil de empleado, que es la pantalla donde ya está
 * trabajando cuando le importa cómo se ve un nombre en un ticket.
 *
 * Límite residual aceptado: una membresía **con** usuario y **sin** perfil —el caso
 * típico del propietario, que no está en nómina— muestra su nombre global, y el
 * tenant no puede sobrescribirlo sin crearle un perfil.
 *
 * ## Por qué existe esta clase y no un accessor en el modelo
 *
 * Para que exista **un** `COALESCE` en todo el proyecto. Con dos orígenes y dos
 * formas de presentación, cada módulo que resolviera el nombre por su cuenta sería
 * una oportunidad de invertir la precedencia sin que nadie lo note.
 */
final class MembershipNameResolver
{
    public function resolve(TenantMembership $membership): PersonName
    {
        $profile = $membership->employeeProfile;

        if ($profile !== null) {
            return $profile->legalName();
        }

        $user = $membership->user;

        if ($user !== null) {
            return $user->name();
        }

        // Invariante I1: una membresía sin credenciales tiene que tener perfil de
        // empleado. Si llegamos aquí, alguien creó la membresía por un camino que no
        // pasa por el servicio de aplicación, y el resultado es una persona sin
        // nombre: una comanda sin mesero identificable y una fila de auditoría que no
        // dice quién actuó.
        throw new RuntimeException(sprintf(
            'La membresía %s no tiene usuario ni perfil de empleado, así que no tiene nombre. '
            .'Viola el invariante I1 (D66): toda membresía sin credenciales debe tener perfil '
            .'de empleado creado en la misma transacción.',
            $membership->ulid,
        ));
    }

    /**
     * Carga previa obligatoria para resolver nombres sin caer en N+1.
     *
     * Con `preventLazyLoading` activo, resolver un nombre sin esta carga lanza
     * excepción en desarrollo y pruebas. Es intencional: la vista de piso muestra
     * treinta mesas con su mesero, y descubrir el N+1 en la hora pico de un
     * restaurante no es una opción.
     *
     * @param  Builder<TenantMembership>  $query
     * @return Builder<TenantMembership>
     */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->with(['user', 'employeeProfile']);
    }

    /**
     * Relaciones a precargar cuando la membresía cuelga de otra entidad
     * (por ejemplo `->with(MembershipNameResolver::relationsFrom('waiter'))`).
     *
     * @return list<string>
     */
    public static function relationsFrom(string $membershipRelation): array
    {
        return [
            "{$membershipRelation}.user",
            "{$membershipRelation}.employeeProfile",
        ];
    }
}
