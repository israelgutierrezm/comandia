<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * TRES DEFECTOS QUE ENCONTRÓ ABRIR LA PANTALLA DE COMANDAS (Iteración 5, paso 15)
 *
 * Los tres con la suite en verde, y ninguno detectable sin operar: hacía falta capturar, comandar, **volver a
 * capturar** y mirar la cocina. Es el caso que nadie escribe por costumbre, otra vez.
 *
 * ## 1. Lo capturado DESPUÉS de comandar no salía nunca
 *
 * El más grave, y el único de los tres que es del POS y no de la pantalla nueva. Capturar sobre una cuenta ya
 * comandada crea una **orden nueva**, y el botón de comandar apuntaba a «la primera orden sin enviar» — que con dos
 * órdenes abiertas es siempre la misma. Resultado: el servidor respondía **201** —comandar una orden ya comandada es
 * idempotente—, la línea se quedaba en «Capturado» para siempre y la comida no se preparaba. Ningún error, ninguna
 * pista.
 *
 * La corrección es que cada línea diga a qué orden pertenece, en lugar de que la pantalla lo adivine.
 *
 * ## 2. Listar comandas respondía 500
 *
 * El nombre visible de una cuenta de mesa se arma con el código de la mesa, así que pintar la lista toca esa relación.
 * Sin precargarla, con el lazy loading deshabilitado, es un 500 — el mismo defecto que D265, donde `displayName()`
 * convertía un 409 en un 500 por tocar una relación no cargada.
 *
 * ## 3. Las áreas no se podían filtrar por sucursal
 *
 * `/preparation-areas` sólo admitía `status`, así que la pantalla ofrecía las áreas de TODO el negocio: la cocina de
 * Polanco aparecía como pestaña en Roma Norte. Es el mismo error de forma que `is_sellable` (D294) — inventar un
 * filtro que la lista blanca no admite— y por eso la corrección es añadirlo de verdad, no cambiar de nombre.
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
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $almacen = Warehouse::query()->first();

    $this->area = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COC',
        'name' => 'Cocina',
    ]);

    $this->terminal = Terminal::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA1',
        'name' => 'Caja 1',
    ]);

    // Un negocio recién provisionado no tiene catálogo.
    $categoria = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);

    $this->articulo = Article::create([
        'name' => 'Comida corrida',
        'category_id' => $categoria->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);

    // El área NO es del artículo: sale del RUTEO (§6.3). Un mismo platillo puede prepararse en la cocina de una
    // sucursal y en la plancha de otra, así que la relación es (sucursal, artículo o categoría) -> área.
    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $categoria->id,
        'preparation_area_id' => $this->area->id,
    ]);

    app(TenantContext::class)->forget();

    $this->abrirCuenta = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
        ->assertCreated()
        ->json('data.ulid');

    $this->capturar = fn (string $cuenta): array => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->articulo->ulid, 'quantity' => '1']],
        ])
        ->assertCreated()
        ->json('data');
});

afterEach(fn () => app(TenantContext::class)->forget());

it('cada línea dice a qué ORDEN pertenece', function () {
    $cuenta = ($this->abrirCuenta)();

    ($this->capturar)($cuenta);

    $primera = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    $ordenUno = $primera['orders'][0]['ulid'];

    expect($primera['items'][0]['order_ulid'])->toBe($ordenUno);

    // Se comanda la primera…
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$ordenUno}/command")
        ->assertCreated();

    // …y se captura DESPUÉS, que es lo que crea la segunda orden.
    ($this->capturar)($cuenta);

    $segunda = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->assertOk()
        ->json('data');

    expect($segunda['orders'])->toHaveCount(2);

    $capturado = collect($segunda['items'])->firstWhere('status', 'captured');

    // LA ASERCIÓN QUE IMPORTA: la línea nueva pertenece a la orden NUEVA, y no a la ya comandada. Sin este dato, la
    // pantalla tenía que adivinar y adivinaba mal.
    expect($capturado['order_ulid'])->not->toBe($ordenUno);
    expect($capturado['order_ulid'])->toBe(collect($segunda['orders'])->firstWhere('sequence', 2)['ulid']);
});

it('listar comandas no revienta por una relación no cargada', function () {
    $cuenta = ($this->abrirCuenta)();
    $orden = ($this->capturar)($cuenta)['orders'][0]['ulid'] ?? null;

    $orden ??= $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/pos-accounts/{$cuenta}")
        ->json('data.orders.0.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command")
        ->assertCreated();

    // Con el lazy loading deshabilitado esto respondía 500, y la pantalla se quedaba «cargando» sin decir por qué.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/pos-tickets?kind=command&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('las áreas se pueden filtrar por sucursal', function () {
    // Sin el filtro, la pantalla de comandas ofrecía las áreas de todo el negocio: la cocina de otra sucursal
    // aparecía como una pestaña de ésta.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/preparation-areas?branch={$this->branch->ulid}&status=active")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cocina');
});
