<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * VISTAS GUARDADAS DE REPORTE (Iteración 7, Tanda B, D45)
 *
 * Prueban que un usuario guarda un reporte con sus parámetros, lo lista reconstruido, y lo borra; y que la vista es
 * personal (otro negocio no la toca).
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

it('guarda una vista, la lista reconstruida y la borra', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/views', [
            'name' => 'Bebidas del mes',
            'group_by' => 'category',
            'sold_from' => '2026-08-01',
        ])
        ->assertCreated()
        ->json('data.ulid');

    // La lista la trae reconstruida: group_by como lista, filtros como pares.
    $vista = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article/views')
        ->assertOk()
        ->json('data.0');

    expect($vista['name'])->toBe('Bebidas del mes');
    expect($vista['group_by'])->toBe(['category']);
    expect($vista['filters']['sold_from'])->toBe('2026-08-01');

    // Se borra.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/report-views/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article/views')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('una vista guardada no es alcanzable desde otro negocio', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/views', ['name' => 'Mía', 'group_by' => 'article'])
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
        ->deleteJson("/api/v1/report-views/{$ulid}")
        ->assertNotFound();
});
