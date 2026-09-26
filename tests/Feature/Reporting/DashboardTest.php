<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TABLEROS Y WIDGETS (Iteración 7, Tanda C, D46)
 *
 * Prueban el ciclo de un tablero —crear, agregar widgets, mostrar, editar, quitar widget, borrar—, que publicarlo lo
 * marca, y que un tablero personal no es visible desde otro negocio.
 *
 * Y que EDITAR valida como crear: el nombre con el mismo tope de 80 (la columna), un nombre vacío como error y no como
 * «no cambió nada», y un rol inexistente como 422 —antes despublicaba el tablero y respondía 200—.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(fn () => app(TenantContext::class)->forget());

it('crea un tablero, le agrega widgets, lo muestra, lo publica y quita uno', function () {
    $dash = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Operación'])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/dashboards/{$dash}/widgets", [
            'report_key' => 'sales.by_article', 'visualization' => 'numero',
            'title' => 'Ventas netas', 'measure_key' => 'net_sales',
        ])->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/dashboards/{$dash}/widgets", [
            'report_key' => 'sales.by_article', 'visualization' => 'barras',
            'title' => 'Por artículo', 'dimension_key' => 'article', 'measure_key' => 'net_sales',
        ])->assertCreated();

    // Se muestra con sus dos widgets, y es mío.
    $vista = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/dashboards/{$dash}")
        ->assertOk()
        ->assertJsonPath('data.is_mine', true)
        ->assertJsonCount(2, 'data.widgets');

    // Aparece en el listado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/dashboards')->assertOk()->assertJsonPath('data.0.ulid', $dash);

    // Publicar a un rol (el dueño tiene el permiso de publicar).
    $roleUlid = app(TenantContext::class)->runFor($this->tenant->id, fn () => Role::query()->value('ulid'));
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['name' => 'Operación diaria', 'published_role_ulid' => $roleUlid])
        ->assertOk()
        ->assertJsonPath('data.name', 'Operación diaria')
        ->assertJsonPath('data.published_role_ulid', $roleUlid);

    // Quitar un widget.
    $widgetUlid = $vista->json('data.widgets.0.ulid');
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/dashboard-widgets/{$widgetUlid}")->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/dashboards/{$dash}")->assertOk()->assertJsonCount(1, 'data.widgets');
});

it('un tablero personal no es visible desde otro negocio, y se borra', function () {
    $dash = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Sólo mío'])
        ->assertCreated()
        ->json('data.ulid');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/dashboards/{$dash}")->assertNotFound();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/dashboards/{$dash}")->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/dashboards')->assertOk()->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// Editar valida como crear
// ---------------------------------------------------------------------------

it('renombrar con más de 80 caracteres responde 422, no 500', function () {
    $dash = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Operación'])
        ->assertCreated()
        ->json('data.ulid');

    // El alta ya lo topaba en 80 —la columna es VARCHAR(80)—; editar no, y la base lo rechazaba con un 500.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['name' => str_repeat('a', 81)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    // El tope exacto sí cabe.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['name' => str_repeat('a', 80)])
        ->assertOk()
        ->assertJsonPath('data.name', str_repeat('a', 80));
});

it('renombrar con un nombre vacío responde 422 en vez de ignorarlo en silencio', function () {
    $dash = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Operación'])
        ->assertCreated()
        ->json('data.ulid');

    // Antes respondía 200 con el nombre de siempre: la pantalla daba por guardado algo que nunca se guardó.
    foreach (['', '   '] as $vacio) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->patchJson("/api/v1/dashboards/{$dash}", ['name' => $vacio])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/dashboards/{$dash}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Operación');
});

it('publicar a un rol que no existe en el negocio responde 422 y NO despublica', function () {
    $dash = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Operación'])
        ->assertCreated()
        ->json('data.ulid');

    $roleUlid = app(TenantContext::class)->runFor($this->tenant->id, fn () => Role::query()->value('ulid'));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['published_role_ulid' => $roleUlid])
        ->assertOk()
        ->assertJsonPath('data.published_role_ulid', $roleUlid);

    // Un rol de OTRO negocio: su ULID existe, pero no aquí. Es el caso de aislamiento de tenant de esta entrada.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    $rolAjeno = app(TenantContext::class)->runFor($otro['tenant']->id, fn () => Role::query()->value('ulid'));
    app(TenantContext::class)->forget();

    // Antes, cualquiera de los dos dejaba el tablero SIN publicar y respondía 200.
    foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', $rolAjeno] as $inexistente) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->patchJson("/api/v1/dashboards/{$dash}", ['published_role_ulid' => $inexistente])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['published_role_ulid']);
    }

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/dashboards/{$dash}")
        ->assertOk()
        ->assertJsonPath('data.published_role_ulid', $roleUlid);

    // Despublicar sigue siendo mandar null a propósito.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['published_role_ulid' => null])
        ->assertOk()
        ->assertJsonPath('data.published_role_ulid', null);
});

it('publicar o despublicar sigue exigiendo dashboards.dashboards.publish; renombrar no', function () {
    // Quien construye tableros pero no publica. Guarda el comportamiento de siempre: el Form Request valida la forma y
    // el permiso de publicar lo sigue exigiendo el controlador en cuanto el cuerpo TRAE `published_role_ulid`.
    app(TenantContext::class)->set($this->tenant->id);
    $rol = Role::create(['name' => 'Arma tableros', 'guard_name' => 'web']);
    $rol->givePermissionTo(['dashboards.dashboards.view', 'dashboards.dashboards.manage']);
    $usuario = User::factory()->create();
    TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'default_role_id' => $rol->id,
        'has_all_branches' => true,
    ]);
    $usuario->assignRole($rol);
    app(TenantContext::class)->forget();

    $dash = $this->actingAsSpa($usuario, $this->tenant->id)
        ->postJson('/api/v1/dashboards', ['name' => 'Mío'])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['name' => 'Mío, renombrado'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Mío, renombrado');

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['published_role_ulid' => $rol->ulid])
        ->assertForbidden();

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->patchJson("/api/v1/dashboards/{$dash}", ['published_role_ulid' => null])
        ->assertForbidden();
});
