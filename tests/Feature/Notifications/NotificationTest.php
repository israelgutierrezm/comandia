<?php

declare(strict_types=1);

use App\Modules\Notifications\Application\Notify;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Storage;

/**
 * CENTRO DE NOTIFICACIONES (Iteración 7, Tanda D2, §6.9)
 *
 * Fijan que un aviso llega a su destinatario y a nadie más, que se listan con su conteo de no leídos y se marcan leídos;
 * y que el primer productor real —«export listo»— avisa al autor cuando su exportación queda lista.
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
    $this->membershipId = $alta['membership']->id;

    $this->avisar = fn (string $title) => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Notify::class)->toMembership($this->membershipId, 'test', $title),
    );
});

afterEach(fn () => app(TenantContext::class)->forget());

it('lista mis avisos con el conteo de no leídos y los marca leídos', function () {
    ($this->avisar)('Primero');
    ($this->avisar)('Segundo');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread', 2)
        ->assertJsonCount(2, 'data');

    $ulid = $respuesta->json('data.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/notifications/{$ulid}/read")->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 1);

    // Marcar todo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/notifications/read-all')->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/notifications')->assertJsonPath('meta.unread', 0);
});

it('un aviso no es visible ni marcable desde otro negocio', function () {
    ($this->avisar)('Sólo mío');

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread', 0);
});

it('avisa al autor cuando su exportación queda lista', function () {
    Storage::fake('local');

    // Pedir un export (la cola corre inline en pruebas): el job crea el aviso al terminar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/exports', ['format' => 'csv'])
        ->assertStatus(202);

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread', 1);

    expect($respuesta->json('data.0.type'))->toBe('export_ready');
    expect($respuesta->json('data.0.url'))->toBe('/admin/reportes');
});
