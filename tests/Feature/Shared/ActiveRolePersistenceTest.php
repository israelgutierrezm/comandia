<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * EL ROL ACTIVO SE RECUERDA, Y SE REINICIA AL INICIAR SESIÓN (D234)
 *
 * ## El defecto que cierra
 *
 * El rol activo viajaba por la cabecera `X-Role` en **una sola visita**, mientras la sucursal sí se recordaba. El
 * selector de la interfaz presentaba como **estado** algo que se deshacía en la navegación siguiente, sin avisar
 * (D228).
 *
 * Lo encontré en el navegador cerrando la Iteración 3: cambié a Almacenista, la hoja de conteo se mostró correctamente
 * ciega, navegué al listado y la columna de diferencias había vuelto. No era la pantalla — era el rol que había vuelto
 * a Propietario.
 *
 * ## Por qué estas pruebas van por HTTP
 *
 * Porque lo que se prueba **es el middleware**. `ResolveTenantContext` es el único lugar que decide el rol activo, y
 * llamar a un servicio con un contexto armado a mano probaría el contexto en lugar de quién lo arma — que es
 * exactamente el hueco por el que la cabecera se perdía sin que nadie lo viera.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->propietario = Role::create(['name' => 'Propietario', 'guard_name' => 'web']);
    $this->propietario->givePermissionTo('catalog.articles.view');

    $this->almacenista = Role::create(['name' => 'Almacenista', 'guard_name' => 'web']);
    $this->almacenista->givePermissionTo('inventory.stock.view');

    $this->user = User::factory()->create();

    $this->membership = TenantMembership::factory()->create([
        'user_id' => $this->user->id,
        'has_all_branches' => true,
        'default_role_id' => $this->propietario->id,
    ]);

    $this->user->assignRole($this->propietario, $this->almacenista);

    /**
     * El rol activo que el servidor resolvió, leído del contexto que expone la API.
     *
     * ## `flushHeaders()` no es cosmético: sin él esta prueba no probaba nada
     *
     * `withHeaders()` de Laravel **persiste** las cabeceras para todas las peticiones siguientes del
     * mismo test. Así que la «petición siguiente sin cabecera» seguía llevando `X-Role`, y la primera
     * versión de esta prueba pasaba en verde **con el middleware sin arreglar**: verificaba que la
     * cabecera funciona, que ya funcionaba antes.
     *
     * Lo descubrí porque otra prueba de este mismo archivo empezó a recibir 403 con el mensaje «No
     * tienes ese rol asignado» — que sólo puede venir de la rama de la cabecera, en una petición que
     * yo creía sin cabecera.
     */
    $this->rolActivo = function (array $headers = []): ?string {
        $this->flushHeaders();

        $response = $this->actingAsSpa($this->user, $this->tenant->id)
            ->withHeaders($headers)
            ->getJson('/api/v1/context')
            ->assertOk();

        return $response->json('data.active_role.name');
    };
});

it('recuerda el rol elegido por cabecera y lo usa en la petición siguiente', function () {
    // Antes de elegir nada, manda el rol por omisión.
    expect(($this->rolActivo)())->toBe('Propietario');

    // Se elige Almacenista por cabecera, como hace el selector de la interfaz.
    expect(($this->rolActivo)(['X-Role' => $this->almacenista->ulid]))->toBe('Almacenista');

    // Y AQUÍ está el defecto que D234 cierra: la petición siguiente NO lleva cabecera, y antes
    // volvía a Propietario.
    expect(($this->rolActivo)())->toBe('Almacenista')
        ->and($this->membership->fresh()->last_active_role_id)->toBe($this->almacenista->id);
});

it('vuelve al rol por omisión si le quitan el rol que tenía recordado', function () {
    ($this->rolActivo)(['X-Role' => $this->almacenista->ulid]);

    expect($this->membership->fresh()->last_active_role_id)->toBe($this->almacenista->id);

    // Le quitan el puesto. La preferencia guardada NO puede sobrevivir a que se le retire el rol:
    // sería un permiso que se conserva por haber navegado.
    $this->user->removeRole($this->almacenista);

    expect(($this->rolActivo)())->toBe('Propietario');
});

it('borrar un rol no bloquea nada y deja la preferencia en nulo', function () {
    ($this->rolActivo)(['X-Role' => $this->almacenista->ulid]);

    // `SET NULL` y no `RESTRICT`: borrar un rol es una operación legítima de la administración, y no
    // debe quedar bloqueada porque alguien lo tuviera activo la semana pasada.
    $this->almacenista->delete();

    expect($this->membership->fresh()->last_active_role_id)->toBeNull()
        ->and(($this->rolActivo)())->toBe('Propietario');
});

it('no escribe la preferencia cuando el rol no cambia', function () {
    ($this->rolActivo)(['X-Role' => $this->almacenista->ulid]);

    $tras = $this->membership->fresh()->updated_at;

    // Diez navegaciones más con el mismo rol. Sin la comparación de `rememberActiveRole()`, cada una
    // haría su UPDATE sobre la misma fila — y esto corre en CADA petición de la jornada.
    foreach (range(1, 10) as $ignored) {
        ($this->rolActivo)(['X-Role' => $this->almacenista->ulid]);
    }

    expect($this->membership->fresh()->updated_at->equalTo($tras))->toBeTrue();
});

it('el rol recordado se reinicia al iniciar sesión', function () {
    // El estado que dejaría la jornada anterior: alguien eligió Almacenista y cerró.
    $this->membership->last_active_role_id = $this->almacenista->id;
    $this->membership->saveQuietly();

    // Y AQUÍ se entra como VISITANTE, sin `actingAs` previo, que es lo que hace la prueba honesta.
    //
    // Mi primera versión autenticaba antes y hacía POST a `/login` esperando que el controlador
    // corriera. No corría: con usuario autenticado y sin negocio en la sesión, `ResolveTenantContext`
    // redirige a la pantalla de selección antes del controlador. La prueba fallaba culpando al
    // arreglo, y el arreglo estaba bien — el login ni se ejecutaba.
    //
    // Y de paso quedó claro que el POST de inicio de sesión se llama `login.store`, no `login`, así
    // que **no** está en la lista de rutas de escape de ese middleware. En producción no importa
    // —quien inicia sesión todavía no está autenticado y el middleware sale por su primera guarda—
    // pero conviene saberlo antes de tocar esa lista.
    $this->post('/login', [
        'email' => $this->user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        // El negocio quedó abierto, o sea que el flujo llegó a `enterTenant()`. El destino exacto lo
        // decide `intended()` y lo cubre `AuthenticationFlowTest`.
        ->assertSessionHas('tenant_id', $this->tenant->id);

    expect($this->membership->fresh()->last_active_role_id)->toBeNull();
});

it('cambiar de negocio con la sesión abierta NO reinicia el rol', function () {
    // Un segundo negocio donde la misma persona también trabaja.
    $otro = Tenant::factory()->create();

    app(TenantContext::class)->runFor($otro->id, function (): void {
        $rol = Role::create(['name' => 'Gerente', 'guard_name' => 'web']);

        TenantMembership::factory()->create([
            'user_id' => $this->user->id,
            'has_all_branches' => true,
            'default_role_id' => $rol->id,
        ]);
    });

    ($this->rolActivo)(['X-Role' => $this->almacenista->ulid]);

    // Cambiar de negocio con la sesión ya abierta. El rol recordado es **por membresía**, así que la
    // preferencia del primer negocio sigue siendo suya: nadie la dejó ahí por descuido.
    $this->actingAsSpa($this->user, $this->tenant->id)
        ->post('/negocios', ['tenant_ulid' => $otro->ulid])
        ->assertRedirect();

    expect($this->membership->fresh()->last_active_role_id)->toBe($this->almacenista->id);
});
