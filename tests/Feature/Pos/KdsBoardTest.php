<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Domain\Enums\PosOrderItemStatus;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL TABLERO DE COCINA (KDS, MVP acotado — D350)
 *
 * Lo que estas pruebas fijan:
 *
 * - El tablero de un área muestra sus **comandas activas** con los platillos vivos, y NADA de otra área.
 * - Lo **capturado sin comandar** no aparece: el tablero es de lo que ya salió a preparar.
 * - Una comanda **desaparece al servirse todo** (el estado de la comanda se DERIVA de sus líneas, D350).
 * - **Aislamiento:** no se lee el tablero de un área de otro negocio; y exige el permiso `pos.kds.view`.
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

    $unidad = Unit::query()->where('code', 'pza')->sole();
    $almacen = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->cocina = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'COCINA',
        'name' => 'Cocina',
        'uses_kds' => true,
    ]);

    $this->barra = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $almacen->id,
        'code' => 'BARRA',
        'name' => 'Barra',
        'uses_kds' => true,
    ]);

    $bebidas = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $bebidas->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '45.00',
        'is_available_in_pos' => true,
    ]);

    $this->tacos = Article::create([
        'name' => 'Tacos de canasta',
        'category_id' => $alimentos->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '60.00',
        'is_available_in_pos' => true,
    ]);

    // Ruteo: bebidas → barra, alimentos → cocina (D240).
    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $bebidas->id,
        'preparation_area_id' => $this->barra->id,
    ]);
    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $alimentos->id,
        'preparation_area_id' => $this->cocina->id,
    ]);

    app(TenantContext::class)->forget();

    $this->abrir = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Mesa de prueba'])
        ->assertCreated()
        ->json('data.ulid');

    /** @param list<Article> $articulos */
    $this->capturar = function (string $cuenta, array $articulos): string {
        $r = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => array_map(fn (Article $a): array => ['article_ulid' => $a->ulid, 'quantity' => '1'], $articulos),
            ])->assertCreated();

        $ordenes = $r->json('data.orders');

        return end($ordenes)['ulid'];
    };

    $this->comandar = fn (string $cuenta, string $orden) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command")->assertSuccessful();

    $this->tablero = fn (PreparationArea $area) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/kds/areas/{$area->ulid}/tickets");
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('lista las comandas activas del área con sus platillos', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [$this->tacos]);
    ($this->comandar)($cuenta, $orden);

    $data = ($this->tablero)($this->cocina)->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['items'])->toHaveCount(1)
        ->and($data[0]['items'][0]['article_name'])->toBe('Tacos de canasta')
        ->and($data[0]['items'][0]['status'])->toBe('commanded')
        ->and($data[0]['issued_at'])->not->toBeNull()
        ->and($data[0]['account']['display_name'])->not->toBeEmpty();
});

it('lo capturado sin comandar no aparece', function () {
    $cuenta = ($this->abrir)();
    ($this->capturar)($cuenta, [$this->tacos]); // NO se comanda

    expect(($this->tablero)($this->cocina)->assertOk()->json('data'))->toBe([]);
});

it('cada área ve sólo sus comandas', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [$this->cafe, $this->tacos]);
    ($this->comandar)($cuenta, $orden);

    $cocina = ($this->tablero)($this->cocina)->assertOk()->json('data');
    $barra = ($this->tablero)($this->barra)->assertOk()->json('data');

    expect($cocina)->toHaveCount(1)
        ->and($cocina[0]['items'][0]['article_name'])->toBe('Tacos de canasta')
        ->and($barra)->toHaveCount(1)
        ->and($barra[0]['items'][0]['article_name'])->toBe('Café americano');
});

it('una comanda desaparece del tablero cuando se sirve todo', function () {
    $cuenta = ($this->abrir)();
    $orden = ($this->capturar)($cuenta, [$this->tacos]);
    ($this->comandar)($cuenta, $orden);

    // Servir la única línea (el avance real llega en el siguiente incremento; aquí se fuerza el estado).
    app(TenantContext::class)->set($this->tenant->id);
    PosOrderItem::query()->where('preparation_area_id', $this->cocina->id)
        ->update(['status' => PosOrderItemStatus::Served->value]);
    app(TenantContext::class)->forget();

    expect(($this->tablero)($this->cocina)->assertOk()->json('data'))->toBe([]);
});

it('no se ve el tablero de un área de OTRO negocio', function () {
    // Otro negocio con su propia área.
    $otra = app(ProvisionTenant::class)->provision(
        businessName: 'Otro Negocio',
        ownerEmail: 'beto@otro.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->set($otra['tenant']->id);
    $almacenAjeno = Warehouse::query()->where('branch_id', $otra['branch']->id)->sole();
    $areaAjena = PreparationArea::create([
        'branch_id' => $otra['branch']->id,
        'warehouse_id' => $almacenAjeno->id,
        'code' => 'COCINA',
        'name' => 'Cocina ajena',
    ]);
    app(TenantContext::class)->forget();

    // El dueño del primer negocio pide el área del segundo: no existe en su alcance de tenant.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/kds/areas/{$areaAjena->ulid}/tickets")
        ->assertNotFound();
});

it('sin el permiso pos.kds.view, 403', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->sole();
    $usuario = User::factory()->create();
    TenantMembership::factory()->create([
        'user_id' => $usuario->id,
        'employee_code' => 'X001',
        'has_all_branches' => true,
        'default_role_id' => $cajero->id,
    ]);
    $usuario->syncRoles([$cajero]); // el cajero no lleva pos.kds.view en su plantilla

    app(TenantContext::class)->forget();

    $this->actingAsSpa($usuario, $this->tenant->id)
        ->getJson("/api/v1/kds/areas/{$this->cocina->ulid}/tickets")
        ->assertStatus(403);
});
