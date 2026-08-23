<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TABLEROS Y WIDGETS (Iteración 7, Tanda C, D46)
 *
 * Prueban el ciclo de un tablero —crear, agregar widgets, mostrar, editar, quitar widget, borrar—, que publicarlo lo
 * marca, y que un tablero personal no es visible desde otro negocio.
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
