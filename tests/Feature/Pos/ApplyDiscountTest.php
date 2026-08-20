<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Finance\Domain\Enums\FinancialMovementType;
use App\Modules\Finance\Infrastructure\Models\FinancialMovement;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * DESCUENTOS Y CORTESÍAS — LA ZONA DE MÁXIMA AUDITORÍA (§6.3, paso 11)
 *
 * ## Las cuatro cosas que estas pruebas existen para demostrar
 *
 * **El monto lo calcula el SERVIDOR.** El cliente manda «10 %» y nunca el resultado: un descuento es la vía más común
 * de sacar dinero de un restaurante sin que parezca robo.
 *
 * **El PIN se pide siempre, incluso a quien tiene el permiso.** El permiso lo tiene la sesión —una terminal abierta que
 * cualquiera puede tocar— y el PIN lo tiene la persona.
 *
 * **Se guardan las DOS personas.** El patrón que un reporte de robo hormiga busca es «el mismo mesero pidiendo
 * autorización veinte veces por turno», y con una sola columna esa pregunta no se puede hacer.
 *
 * **No se descuenta una cuenta ya pagada.** Cobrar el total, descontar después y quedarse la diferencia es exactamente
 * la maniobra que §6.3 quiere impedir.
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
    $categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);

    $this->cafe = Article::create([
        'name' => 'Café americano',
        'category_id' => $categoria->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);

    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole();

    $this->terminal = Terminal::create([
        'branch_id' => $this->branch->id,
        'code' => 'CAJA1',
        'name' => 'Caja 1',
    ]);

    // Un gerente con PIN, que es quien autoriza.
    $rol = Role::query()->where('name', RoleTemplates::MANAGER)->sole();
    $usuarioGerente = User::factory()->create();

    $this->gerente = TenantMembership::factory()->create([
        'user_id' => $usuarioGerente->id,
        'employee_code' => 'G001',
        'has_all_branches' => true,
        'default_role_id' => $rol->id,
    ]);

    $usuarioGerente->syncRoles([$rol]);
    app(ManageMembershipPin::class)->set($this->gerente, '1111');

    app(TenantContext::class)->forget();

    /** Abre caja y una cuenta con dos cafés (200.00). Devuelve el ULID de la cuenta. */
    $this->cuentaDe200 = function (): string {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', [
                'terminal_ulid' => $this->terminal->ulid,
                'opening_float' => '500.00',
            ])
            ->assertCreated();

        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra 1'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->cafe->ulid, 'quantity' => '2']],
            ])
            ->assertCreated();

        return $cuenta;
    };

    /** Un token de autorización para el permiso pedido. */
    $this->autorizacion = fn (string $permiso): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/authorizations', [
            'employee_code' => 'G001',
            'pin' => '1111',
            'permission' => $permiso,
        ])
        ->assertCreated()
        ->json('data.token');

    /** Aplica un descuento. */
    $this->descontar = fn (string $cuenta, array $datos) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/discounts", $datos);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

// ---------------------------------------------------------------------------
// El PIN
// ---------------------------------------------------------------------------

it('descontar sin PIN responde 409 con el permiso que falta', function () {
    $cuenta = ($this->cuentaDe200)();

    // El dueño TIENE el permiso y aun así necesita PIN: el permiso lo tiene la sesión, el PIN lo tiene la persona.
    ($this->descontar)($cuenta, [
        'kind' => 'percentage',
        'value' => '10',
        'reason' => 'Cliente frecuente',
    ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'authorization_required')
        ->assertJsonPath('required_permission', 'pos.discounts.apply_account');
});

it('un descuento de ITEM pide otro permiso que uno de cuenta', function () {
    $cuenta = ($this->cuentaDe200)();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    ($this->descontar)($cuenta, [
        'kind' => 'amount',
        'value' => '20',
        'reason' => 'Salió frío',
        'item_ulid' => $item->ulid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('required_permission', 'pos.discounts.apply_item');
});

it('una cortesía pide el permiso de cortesías', function () {
    $cuenta = ($this->cuentaDe200)();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    ($this->descontar)($cuenta, [
        'kind' => 'courtesy',
        'reason' => 'Cumpleaños del cliente',
        'item_ulid' => $item->ulid,
    ])
        ->assertStatus(409)
        ->assertJsonPath('required_permission', 'pos.discounts.courtesy');
});

it('una autorización no sirve para dos descuentos', function () {
    $cuenta = ($this->cuentaDe200)();
    $token = ($this->autorizacion)('pos.discounts.apply_account');

    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '10', 'reason' => 'Cliente frecuente', 'authorization_token' => $token,
    ])->assertOk();

    // Sin esto, un PIN pedido una vez descontaría toda la noche.
    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '10', 'reason' => 'Otra vez', 'authorization_token' => $token,
    ])->assertStatus(422);
});

// ---------------------------------------------------------------------------
// El monto lo calcula el servidor
// ---------------------------------------------------------------------------

it('un 10 % de 200 son 20, y el cliente no manda el monto', function () {
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'percentage',
        'value' => '10',
        'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])
        ->assertOk()
        ->assertJsonPath('data.totals.total', '180.00')
        ->assertJsonPath('data.totals.discount_total', '20.00')
        ->assertJsonPath('data.discounts.0.resulting_amount', '20.00')
        // El valor pedido se conserva junto al resultado: sin él, «¿fue un 10 % o veinte pesos?» no se puede contestar.
        ->assertJsonPath('data.discounts.0.value', '10.00');
});

it('un descuento de ITEM baja el total de la línea, no el de la cuenta por su cuenta', function () {
    // La distinción importa o el total sale mal en las dos direcciones: un descuento de item ya vive dentro de
    // `line_total` —columna generada— así que restarlo otra vez en el recálculo lo descontaría dos veces.
    $cuenta = ($this->cuentaDe200)();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    ($this->descontar)($cuenta, [
        'kind' => 'amount',
        'value' => '50',
        'reason' => 'Salió frío',
        'item_ulid' => $item->ulid,
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_item'),
    ])
        ->assertOk()
        ->assertJsonPath('data.totals.total', '150.00')
        ->assertJsonPath('data.totals.discount_total', '50.00');
});

it('un descuento no puede ser mayor que su base', function () {
    $cuenta = ($this->cuentaDe200)();

    // 500 sobre una cuenta de 200 dejaría un total negativo: el negocio pagándole al cliente.
    ($this->descontar)($cuenta, [
        'kind' => 'amount',
        'value' => '500',
        'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertStatus(409);
});

it('un porcentaje mayor a 100 se rechaza en la validación', function () {
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'percentage',
        'value' => '120',
        'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertStatus(409);
});

it('dos descuentos del 50 % dejan un 25 %, no cero', function () {
    // Se descuenta sobre la base VIVA. Es lo que espera quien opera —«otro 50 % encima»— y es lo que impide que dos
    // descuentos sumen más que la cuenta.
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'percentage', 'value' => '50', 'reason' => 'Promoción',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertOk()->assertJsonPath('data.totals.total', '100.00');

    ($this->descontar)($cuenta, [
        'kind' => 'percentage', 'value' => '50', 'reason' => 'Y otra',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertOk()->assertJsonPath('data.totals.total', '50.00');
});

// ---------------------------------------------------------------------------
// El motivo y las dos personas
// ---------------------------------------------------------------------------

it('el motivo es obligatorio', function () {
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'amount',
        'value' => '10',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);
});

it('guarda a quien lo APLICÓ y a quien lo AUTORIZÓ, por separado', function () {
    $cuenta = ($this->cuentaDe200)();

    $respuesta = ($this->descontar)($cuenta, [
        'kind' => 'amount',
        'value' => '25',
        'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertOk();

    // Que sean dos columnas es el punto entero: el patrón que el reporte busca es «el mismo mesero pidiendo
    // autorización veinte veces por turno», y con una sola no se puede preguntar.
    expect($respuesta->json('data.discounts.0.applied_by.employee_code'))->toBe('P001');
    expect($respuesta->json('data.discounts.0.authorized_by.employee_code'))->toBe('G001');
});

// ---------------------------------------------------------------------------
// La cortesía
// ---------------------------------------------------------------------------

it('una cortesía deja la línea en cero y la MARCA', function () {
    $cuenta = ($this->cuentaDe200)();

    app(TenantContext::class)->set($this->tenant->id);
    $item = PosOrderItem::query()->sole();
    app(TenantContext::class)->forget();

    ($this->descontar)($cuenta, [
        'kind' => 'courtesy',
        'reason' => 'Cumpleaños del cliente',
        'item_ulid' => $item->ulid,
        'authorization_token' => ($this->autorizacion)('pos.discounts.courtesy'),
    ])
        ->assertOk()
        ->assertJsonPath('data.totals.total', '0.00');

    app(TenantContext::class)->set($this->tenant->id);

    // La marca es la que hace que una cortesía SÍ descuente inventario (§6.3): el plato se preparó y los insumos se
    // gastaron, aunque no se cobrara.
    expect(PosOrderItem::query()->sole()->is_courtesy)->toBeTrue();
});

it('una cortesía sin item se rechaza', function () {
    $cuenta = ($this->cuentaDe200)();

    // Regalar la mesa entera es un descuento del 100 %, que sí existe y deja rastro como tal.
    ($this->descontar)($cuenta, [
        'kind' => 'courtesy',
        'reason' => 'Cumpleaños del cliente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.courtesy'),
    ])->assertStatus(409);
});

// ---------------------------------------------------------------------------
// El diario y la inmutabilidad
// ---------------------------------------------------------------------------

it('asienta el descuento en NEGATIVO, y la cortesía con su propio tipo', function () {
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '25', 'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = FinancialMovement::query()->where('type', FinancialMovementType::Discount->value)->sole();

    // En positivo, un descuento AUMENTARÍA la venta. Desde el paso 10 el diario rechaza el signo equivocado.
    expect((string) $asiento->amount)->toBe('-25.00');

    // El actor es quien AUTORIZÓ: es la firma que el reporte agrupa.
    expect((int) $asiento->actor_membership_id)->toBe($this->gerente->id);
});

it('no se descuenta una cuenta ya PAGADA', function () {
    $cuenta = ($this->cuentaDe200)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo->ulid, 'amount' => '200.00']],
        ])
        ->assertOk();

    // Cobrar el total, descontar después y quedarse la diferencia es exactamente la maniobra que §6.3 impide.
    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '25', 'reason' => 'Tarde',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertStatus(409);
});

it('un descuento es INMUTABLE', function () {
    $cuenta = ($this->cuentaDe200)();

    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '25', 'reason' => 'Cliente frecuente',
        'authorization_token' => ($this->autorizacion)('pos.discounts.apply_account'),
    ])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => PosDiscount::query()->sole()->update(['resulting_amount' => '1.00']))
        ->toThrow(RuntimeException::class);
});

it('sin caja abierta no se descuenta', function () {
    // Un descuento es dinero que se dejó de cobrar y el corte tiene que poder explicarlo: sin turno, el asiento no
    // tendría a qué arqueo pertenecer.
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra 1'])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [['article_ulid' => $this->cafe->ulid, 'quantity' => '1']],
        ])
        ->assertCreated();

    ($this->descontar)($cuenta, [
        'kind' => 'amount', 'value' => '10', 'reason' => 'Cliente frecuente',
    ])->assertStatus(409);
});
