<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Caso base de todas las suites.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Siembra el catálogo de permisos una sola vez por corrida.
     *
     * `RefreshDatabase` migra una vez y envuelve cada prueba en una transacción; el
     * sembrado ocurre junto con la migración, o sea **fuera** de esa transacción, así que
     * los ~130 permisos del catálogo se insertan una vez y no una por prueba.
     *
     * Medido antes de esto: la suite de middleware tardaba 56 s, casi todo en resembrar.
     * El catálogo es del sistema y no cambia entre pruebas, así que resembrarlo no
     * aportaba aislamiento: sólo tiempo.
     */
    protected bool $seed = true;

    /**
     * Autentica como la SPA de Vue lo haría de verdad.
     *
     * ## Por qué hace falta un helper y no basta `actingAs()`
     *
     * Las rutas de `/api/v1` usan el guard de Sanctum. Para que una petición a la API
     * se autentique **por cookie de sesión** —el caso de la SPA— Sanctum exige que la
     * petición venga de un dominio declarado como *stateful*, y lo determina por las
     * cabeceras `Referer` u `Origin`. Un navegador real siempre las manda; el cliente de
     * pruebas de Laravel no.
     *
     * Sin este helper, una petición de prueba a la API no arranca sesión y el middleware
     * de contexto no encuentra el tenant — un fallo que parece del middleware y es del
     * montaje de la prueba.
     *
     * La app Flutter y los agentes de impresión NO pasan por aquí: usan token, y para
     * eso está `IssueApiToken`.
     *
     * ## Por qué empieza tirando la sesión
     *
     * Porque `withSession()` **mezcla** en la sesión que la prueba ya traía, y la de la petición anterior sigue ahí con
     * la llave de autenticación del usuario anterior. Al cambiar de usuario dentro de una misma prueba, el guard de
     * sesión resolvía la sesión vieja y respondía **401 «No has iniciado sesión»** — con la membresía activa y todo en
     * orden.
     *
     * Es la misma familia del `flushHeaders()` del paso 0 de la Iteración 4: estado del cliente de pruebas que
     * sobrevive de una petición a la siguiente y hace que la prueba mida otra cosa de la que cree.
     *
     * Y era más caro de lo que parece. Sin esto, «autentícate como otro» no funcionaba, así que varias pruebas de
     * autorización y de aislamiento preparaban al usuario ajeno **por modelos** y nunca ejercitaban su camino HTTP —
     * que es justo el camino que esas pruebas existen para vigilar. El arreglo va aquí y no en cada prueba: una
     * limitación del ayudante no debería moldear cómo se escriben las pruebas.
     */
    protected function actingAsSpa(User $user, int $tenantId): static
    {
        $this->flushSession();

        return $this
            ->withHeader('Referer', (string) config('app.url'))
            ->withSession(['tenant_id' => $tenantId])
            ->actingAs($user);
    }
}
