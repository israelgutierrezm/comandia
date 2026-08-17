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
     */
    protected function actingAsSpa(User $user, int $tenantId): static
    {
        return $this
            ->withHeader('Referer', (string) config('app.url'))
            ->withSession(['tenant_id' => $tenantId])
            ->actingAs($user);
    }
}
