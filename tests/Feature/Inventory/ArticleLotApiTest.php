<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * API DE LOTES (D23)
 *
 * Estos endpoints existen aunque FEFO sea automático. El permiso `inventory.lots.manage` llevaba dos iteraciones
 * en el catálogo cerrado **sin ruta**, que es el defecto que la revisión de la Iteración 2 encontró con otro
 * permiso (D140): un tenant lo concede y no pasa nada.
 *
 * Y hacen falta de verdad: corregir una caducidad mal teclada y dar un lote por caducado son decisiones que sólo
 * una persona toma. El sistema no da la mercancía por perdida por su cuenta.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Caducidades',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Estela',
        ownerPaternalSurname: 'Prado',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $ml = Unit::query()->where('code', 'ml')->firstOrFail();

    $this->leche = Article::create([
        'name' => 'Leche entera',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    $this->jitomate = Article::create([
        'name' => 'Jitomate',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('crea un lote con su caducidad', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->leche->ulid}/lots", [
            'code' => 'L-2026-03',
            'expires_at' => '2026-12-31',
            'received_at' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'L-2026-03')
        ->assertJsonPath('data.expires_at', '2026-12-31')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.can_be_issued', true)
        ->assertJsonPath('data.is_expired', false);
});

it('un lote SIN caducidad es válido y distinto de una fecha lejana', function () {
    // La sal no caduca. Ponerle el año 2099 sería inventar un dato que alguien leería como real.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->leche->ulid}/lots", [
            'code' => 'L-SIN-CAD',
            'expires_at' => null,
            'received_at' => now()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.expires_at', null)
        ->assertJsonPath('data.is_expired', false);
});

it('rechaza un lote de un artículo que NO se controla por lotes', function () {
    // Ese lote sería invisible para FEFO: existencia que nadie encontraría.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/lots", [
            'code' => 'L-X',
            'received_at' => now()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

it('rechaza una caducidad anterior a la recepción', function () {
    // Error de captura frecuente —teclear el año anterior— y con FEFO ese lote saldría PRIMERO, vaciando lo que
    // sí servía y dejando en el almacén lo que caduca de verdad.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->leche->ulid}/lots", [
            'code' => 'L-MAL',
            'expires_at' => now()->subYear()->toDateString(),
            'received_at' => now()->toDateString(),
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['expires_at']]);
});

it('no admite dos lotes con el mismo código para el mismo artículo', function () {
    foreach ([1, 2] as $intento) {
        $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/articles/{$this->leche->ulid}/lots", [
                'code' => 'L-REPE',
                'received_at' => now()->toDateString(),
            ]);

        $intento === 1
            ? $respuesta->assertCreated()
            : $respuesta->assertStatus(422)->assertJsonStructure(['errors' => ['code']]);
    }
});

it('el mismo código SÍ puede existir en otro artículo', function () {
    // Dos proveedores distintos pueden usar la misma nomenclatura para productos que no tienen nada que ver.
    app(TenantContext::class)->set($this->tenant->id);
    $this->jitomate->update(['tracks_lots' => true]);
    app(TenantContext::class)->forget();

    foreach ([$this->leche, $this->jitomate] as $articulo) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/articles/{$articulo->ulid}/lots", [
                'code' => 'L-001',
                'received_at' => now()->toDateString(),
            ])
            ->assertCreated();
    }
});

it('el listado viene en orden FEFO, con los que no caducan al final', function () {
    app(TenantContext::class)->set($this->tenant->id);

    foreach ([
        ['L-SIN', null],
        ['L-MAY', now()->addMonths(3)->toDateString()],
        ['L-MAR', now()->addMonth()->toDateString()],
    ] as [$code, $expires]) {
        ArticleLot::create([
            'article_id' => $this->leche->id,
            'code' => $code,
            'expires_at' => $expires,
            'received_at' => now()->subDay()->toDateString(),
        ]);
    }

    app(TenantContext::class)->forget();

    $lotes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->leche->ulid}/lots")
        ->assertOk()
        ->json('data');

    // El mismo orden con el que va a salir: así la pantalla dice, sin que nadie lo explique, de dónde sale lo
    // siguiente.
    expect(array_column($lotes, 'code'))->toBe(['L-MAR', 'L-MAY', 'L-SIN']);
});

it('la caducidad se puede CORREGIR y el código no', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $lote = ArticleLot::create([
        'article_id' => $this->leche->id,
        'code' => 'L-CORR',
        'expires_at' => now()->addMonth()->toDateString(),
        'received_at' => now()->subDay()->toDateString(),
    ]);

    app(TenantContext::class)->forget();

    // La caducidad es un dato del envase que se pudo teclear mal. Corregirla no reinterpreta ningún movimiento
    // pasado: sólo cambia el orden en que saldrá lo que queda, que es lo que se quiere.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/lots/{$lote->ulid}", ['expires_at' => now()->addMonths(6)->toDateString()])
        ->assertOk()
        ->assertJsonPath('data.expires_at', now()->addMonths(6)->toDateString());

    // El código NO: los movimientos ya lo citan, y reasignarlo reinterpretaría existencias que ya se movieron.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/lots/{$lote->ulid}", ['code' => 'L-OTRO'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['code']]);
});

it('el modelo tampoco deja cambiar el código, no sólo el Form Request', function () {
    // La validación da el mensaje; el modelo da la garantía para seeders e importaciones. Son dos defensas y no
    // una duplicación.
    app(TenantContext::class)->set($this->tenant->id);

    $lote = ArticleLot::create([
        'article_id' => $this->leche->id,
        'code' => 'L-FIJO',
        'received_at' => now()->toDateString(),
    ]);

    expect(fn () => $lote->update(['code' => 'L-CAMBIADO']))->toThrow(RuntimeException::class);

    app(TenantContext::class)->forget();
});

it('marcar un lote como caducado NO registra la merma', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $lote = ArticleLot::create([
        'article_id' => $this->leche->id,
        'code' => 'L-VENC',
        'expires_at' => now()->addDay()->toDateString(),
        'received_at' => now()->subDay()->toDateString(),
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/lots/{$lote->ulid}/expire")
        ->assertOk()
        ->assertJsonPath('data.status', 'expired')
        ->assertJsonPath('data.can_be_issued', false);

    // Deliberado: dar la mercancía por perdida sola convertiría un vencimiento de calendario en una pérdida
    // contable que nadie revisó. Muchas veces se revisa el lote y parte se salva.
    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): int => StockMovement::query()->count(),
    ))->toBe(0);
});

it('el mesero no administra lotes', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson("/api/v1/articles/{$this->leche->ulid}/lots", [
            'code' => 'L-NO',
            'received_at' => now()->toDateString(),
        ])
        ->assertForbidden();
});
