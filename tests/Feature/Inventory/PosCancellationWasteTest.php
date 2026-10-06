<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\SystemWasteReasons;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Inventory\Jobs\RegisterPosCancellationWaste;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Pos\Domain\Enums\PosTicketKind;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Str;

/**
 * LA MERMA DE LO QUE SE CANCELÓ YA PREPARADO (D375, §6.3)
 *
 * La venta descuenta al cobrar, y lo cancelado no se cobra: el platillo que la cocina hizo y se tiró nunca salía del
 * inventario. El siguiente conteo daba los insumos por faltantes sin explicación y la merma no aparecía en su reporte.
 *
 * Ahora, cancelar con destino «merma» descuenta lo mismo que habría descontado la venta —la receta, del almacén del
 * área— con el motivo del sistema «Cancelación en POS». «Devolver a existencias» no mueve nada: la venta nunca lo
 * descontó.
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
    $this->membership = $alta['membership'];

    app(TenantContext::class)->set($this->tenant->id);

    $pieza = Unit::query()->where('code', 'pza')->sole();
    $gramo = Unit::query()->where('code', 'g')->sole();

    $this->almacenSucursal = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->almacenCocina = Warehouse::create([
        'branch_id' => $this->branch->id,
        'kind' => 'branch',
        'code' => 'ALM-COCINA',
        'name' => 'Almacén de cocina',
    ]);

    $this->cocina = PreparationArea::create([
        'branch_id' => $this->branch->id,
        'warehouse_id' => $this->almacenCocina->id,
        'code' => 'COCINA',
        'name' => 'Cocina',
    ]);

    $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1]);
    $nevera = ArticleCategory::create(['name' => 'De la nevera', 'level' => 1]);

    $this->carne = Article::create([
        'name' => 'Carne molida',
        'category_id' => $alimentos->id,
        'base_unit_id' => $gramo->id,
        'is_inventoriable' => true,
        'is_supply' => true,
    ]);

    $this->hamburguesa = Article::create([
        'name' => 'Hamburguesa',
        'category_id' => $alimentos->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'is_producible' => true,
        'base_price' => '120.00',
        'is_available_in_pos' => true,
    ]);

    app(SaveRecipe::class)->save($this->hamburguesa, [[
        'component_article_id' => $this->carne->id,
        'quantity' => '150.0000',
        'unit_id' => $gramo->id,
    ]], outputQuantity: '1');

    // Sin regla de ruteo: no tiene área. El mesero la saca de la nevera.
    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $nevera->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'is_inventoriable' => true,
        'base_price' => '50.00',
        'is_available_in_pos' => true,
    ]);

    // Un vendible que no controla existencias.
    $this->servicio = Article::create([
        'name' => 'Descorche',
        'category_id' => $nevera->id,
        'base_unit_id' => $pieza->id,
        'is_sellable' => true,
        'base_price' => '80.00',
        'is_available_in_pos' => true,
    ]);

    PosAreaRoute::create([
        'branch_id' => $this->branch->id,
        'article_category_id' => $alimentos->id,
        'preparation_area_id' => $this->cocina->id,
    ]);

    app(TenantContext::class)->forget();

    $this->gerente = gerenteQueAutorizaLaMerma($this->tenant->id);

    /**
     * Abre una cuenta, captura y comanda las líneas dadas. Devuelve el ULID de la cuenta y los ULID de sus líneas por
     * nombre de artículo.
     *
     * @param  list<array{0: Article, 1?: string}>  $lineas
     * @return array{0: string, 1: array<string, string>}
     */
    $this->comandarCuenta = function (array $lineas): array {
        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Mesa 4'])
            ->assertCreated()
            ->json('data.ulid');

        $ordenes = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => array_map(fn (array $l): array => [
                    'article_ulid' => $l[0]->ulid,
                    'quantity' => $l[1] ?? '1',
                ], $lineas),
            ])
            ->assertCreated()
            ->json('data.orders');

        $orden = end($ordenes)['ulid'];

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders/{$orden}/command")
            ->assertCreated();

        $items = app(TenantContext::class)->runFor($this->tenant->id, fn () => PosOrderItem::query()
            ->whereHas('account', fn ($q) => $q->where('ulid', $cuenta))
            ->pluck('ulid', 'article_name')
            ->all());

        return [$cuenta, $items];
    };

    /** Cancela líneas comandadas con el PIN del gerente. */
    $this->cancelar = function (string $cuenta, array $itemUlids, string $destino) {
        $token = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/authorizations', [
                'employee_code' => 'G001',
                'pin' => '1111',
                'permission' => 'pos.items.cancel_commanded',
            ])
            ->assertCreated()
            ->json('data.token');

        return $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/items/cancel", [
                'item_ulids' => $itemUlids,
                'reason' => 'Se quemó en la plancha',
                'destination' => $destino,
                'authorization_token' => $token,
            ])
            ->assertOk();
    };
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('un platillo cancelado como MERMA descuenta su receta del almacén de su área, con el motivo del sistema', function () {
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->hamburguesa, '2']]);

    ($this->cancelar)($cuenta, [$items['Hamburguesa']], 'waste');

    app(TenantContext::class)->set($this->tenant->id);

    $merma = StockMovement::query()->where('kind', StockMovementKind::Waste->value)->with('wasteReason')->sole();

    // Lo mismo que habría descontado la venta: dos hamburguesas de 150 g de carne, de la cocina.
    expect((int) $merma->article_id)->toBe($this->carne->id);
    expect((string) $merma->quantity)->toBe('300.0000');
    expect((int) $merma->warehouse_id)->toBe($this->almacenCocina->id);

    // Con motivo, y uno que el negocio no puede renombrar: el reporte de mermas no puede mentir.
    expect($merma->wasteReason?->name)->toBe(SystemWasteReasons::POS_CANCELLATION);
    expect($merma->wasteReason?->is_system)->toBeTrue();

    // Quién la pidió y de qué cuenta, para quien investigue.
    expect((int) $merma->actor_membership_id)->toBe($this->membership->id);
    expect($merma->notes)->toBe('Cancelación en POS · Mesa 4');

    // Y nada se vendió.
    expect(StockMovement::query()->where('kind', StockMovementKind::SaleConsumption->value)->count())->toBe(0);
});

it('cancelar para DEVOLVER a existencias no mueve el inventario', function () {
    // La venta descuenta al cobrar y esto no se cobró: no hay nada que devolver.
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->hamburguesa], [$this->cerveza]]);

    ($this->cancelar)($cuenta, array_values($items), 'restock');

    app(TenantContext::class)->set($this->tenant->id);

    expect(StockMovement::query()->count())->toBe(0);
    expect(WasteReason::query()->where('name', SystemWasteReasons::POS_CANCELLATION)->exists())->toBeFalse();
});

it('lo que NO tiene área se merma del almacén de la sucursal, sin comanda de cancelación', function () {
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->cerveza, '3']]);

    ($this->cancelar)($cuenta, [$items['Cerveza']], 'waste');

    app(TenantContext::class)->set($this->tenant->id);

    $merma = StockMovement::query()->where('kind', StockMovementKind::Waste->value)->with('wasteReason')->sole();

    expect((int) $merma->article_id)->toBe($this->cerveza->id);
    expect((string) $merma->quantity)->toBe('3.0000');
    expect((int) $merma->warehouse_id)->toBe($this->almacenSucursal->id);
    expect($merma->wasteReason?->name)->toBe(SystemWasteReasons::POS_CANCELLATION);

    // Ningún área la preparó: el aviso sale sin comanda de cancelación, porque no hay a quién mandarle el papel.
    expect(PosTicket::query()->where('kind', PosTicketKind::CommandCancellation->value)->count())->toBe(0);
});

it('un artículo que no controla existencias no deja merma, ni crea el motivo', function () {
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->servicio]]);

    ($this->cancelar)($cuenta, [$items['Descorche']], 'waste');

    app(TenantContext::class)->set($this->tenant->id);

    expect(StockMovement::query()->count())->toBe(0);

    // Sembrarlo sin merma que agrupar sería un motivo más en el catálogo de quien nunca lo usa.
    expect(WasteReason::query()->where('name', SystemWasteReasons::POS_CANCELLATION)->exists())->toBeFalse();
});

it('re-despachar el trabajo NO duplica la merma', function () {
    // El mecanismo de reparación es re-despachar: sin idempotencia, reparar duplicaría lo que ya se escribió.
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->hamburguesa], [$this->cerveza]]);

    ($this->cancelar)($cuenta, array_values($items), 'waste');

    app(TenantContext::class)->set($this->tenant->id);

    $antes = StockMovement::query()->where('kind', StockMovementKind::Waste->value)->orderBy('id')->get();
    expect($antes)->toHaveCount(2);

    app(TenantContext::class)->forget();

    // El aviso de la cocina, otra vez, con los mismos items.
    dispatch_sync(new RegisterPosCancellationWaste(
        $this->tenant->id,
        (int) $this->branch->id,
        $cuenta,
        'Mesa 4',
        $this->cocina->id,
        [['item_ulid' => $items['Hamburguesa'], 'article_id' => $this->hamburguesa->id, 'quantity' => '1.0000']],
        $this->membership->id,
    ));

    app(TenantContext::class)->set($this->tenant->id);

    $despues = StockMovement::query()->where('kind', StockMovementKind::Waste->value)->orderBy('id')->get();

    expect($despues->pluck('id')->all())->toBe($antes->pluck('id')->all());
    expect($despues->pluck('waste_reason_id')->unique()->count())->toBe(1);

    // Y el motivo tampoco se duplica entre cancelaciones.
    expect(WasteReason::query()->where('name', SystemWasteReasons::POS_CANCELLATION)->count())->toBe(1);
});

it('quién pidió la cancelación viaja en el trabajo: en la cola no hay sesión de la que leerlo', function () {
    // En las demás pruebas el trabajo corre dentro de la petición, y el kardex podría sacar al actor de su contexto. En
    // producción corre en un proceso de la cola, sin petición: si el actor no viajara en el trabajo, la merma quedaría sin
    // quién la pidió. Se corre sin contexto y con un actor distinto de quien hizo las peticiones de esta prueba.
    app(ContextHolder::class)->forget();

    dispatch_sync(new RegisterPosCancellationWaste(
        $this->tenant->id,
        (int) $this->branch->id,
        (string) Str::ulid(),
        'Mesa 9',
        null,
        [['item_ulid' => (string) Str::ulid(), 'article_id' => $this->cerveza->id, 'quantity' => '1.0000']],
        $this->gerente->id,
    ));

    $merma = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => StockMovement::query()->where('kind', StockMovementKind::Waste->value)->sole(),
    );

    expect((int) $merma->actor_membership_id)->toBe($this->gerente->id);
    expect($merma->notes)->toBe('Cancelación en POS · Mesa 9');
});

it('la merma aparece en el reporte de mermas como «Cancelación en POS»', function () {
    [$cuenta, $items] = ($this->comandarCuenta)([[$this->cerveza, '2']]);

    ($this->cancelar)($cuenta, [$items['Cerveza']], 'waste');

    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/inventory.waste')
        ->assertOk()
        ->json('data.rows');

    expect(collect($filas)->pluck('reason')->all())->toBe([SystemWasteReasons::POS_CANCELLATION]);
    expect($filas[0]['quantity'])->toBe('2.0000');
});

it('el motivo es de cada negocio: el de otro no se toca ni se usa', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café de la Esquina',
        ownerEmail: 'luis@esquina.mx',
        ownerFirstName: 'Luis',
        ownerPaternalSurname: 'Ríos',
        plainPassword: 'secreto-largo-456',
    )['tenant'];

    $ajeno = app(TenantContext::class)->runFor($otro->id, fn () => WasteReason::create([
        'name' => SystemWasteReasons::POS_CANCELLATION,
    ]));

    [$cuenta, $items] = ($this->comandarCuenta)([[$this->cerveza]]);

    ($this->cancelar)($cuenta, [$items['Cerveza']], 'waste');

    $propio = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => StockMovement::query()->where('kind', StockMovementKind::Waste->value)->sole()->waste_reason_id,
    );

    expect((int) $propio)->not->toBe($ajeno->id);

    // El del otro negocio sigue como lo dejó su dueño.
    app(TenantContext::class)->runFor($otro->id, function () use ($ajeno): void {
        expect(WasteReason::query()->find($ajeno->id)?->is_system)->toBeFalse();
    });
});

/**
 * Un gerente con PIN, para autorizar las cancelaciones de esta prueba.
 */
function gerenteQueAutorizaLaMerma(int $tenantId): TenantMembership
{
    return app(TenantContext::class)->runFor($tenantId, function (): TenantMembership {
        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->sole();

        $usuario = User::factory()->create();

        $gerente = TenantMembership::factory()->create([
            'user_id' => $usuario->id,
            'employee_code' => 'G001',
            'has_all_branches' => true,
            'default_role_id' => $rol->id,
        ]);

        $usuario->syncRoles([$rol]);

        app(ManageMembershipPin::class)->set($gerente, '1111');

        return $gerente;
    });
}
