<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Identity\Application\PinAuthorization\PinAuthorizationService;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MERMAS: MOTIVO, UMBRAL Y AUTORIZACIÓN POR PIN (D27, §6.2)
 *
 * Las cuatro reglas de §6.2, y lo que cada prueba cuida:
 *
 *   1. **Catálogo de motivos por tenant** — obligatorio, activo, y con nombre único para que el reporte agrupado
 *      signifique algo.
 *   2. **Permiso específico** — `inventory.waste.create`.
 *   3. **Umbral de monto con autorización superior** — evaluado sobre el VALOR y no sobre la cantidad, porque cien
 *      gramos de azafrán y cien kilos de sal no son la misma pérdida.
 *   4. **Evidencia opcional** — diferida (P5); la política del motivo sí existe y viaja.
 *
 * Y la regla que las une: **quien registra no puede autorizarse**. Es la primera vez en el proyecto que el PIN de
 * ADR-008 se usa fuera de su propio endpoint.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda con Mermas',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Silvia',
        ownerPaternalSurname: 'Delgado',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate saladet',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $this->motivo = WasteReason::create(['name' => 'Se cayó al piso']);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Cuerpo mínimo de una merma. */
function merma(array $extra = []): array
{
    return array_merge([
        'warehouse_ulid' => test()->warehouse->ulid,
        'article_ulid' => test()->jitomate->ulid,
        'waste_reason_ulid' => test()->motivo->ulid,
        'quantity' => '100',
    ], $extra);
}

/** Le pone costo al jitomate, para que la merma tenga valor. */
function conCosto(string $unitCost): void
{
    app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn () => app(CaptureArticleCost::class)->atUnitCost(test()->jitomate, $unitCost),
    );
}

/**
 * Crea a un gerente con PIN que SÍ puede autorizar mermas, y devuelve su concesión.
 *
 * El autorizador es otra persona a propósito: quien registra no puede autorizarse, y ésa es la regla que el umbral
 * defiende.
 */
function concesionDeGerente(): string
{
    return app(TenantContext::class)->runFor(test()->tenant->id, function (): string {
        $gerente = User::factory()->create(['first_name' => 'Hilda', 'paternal_surname' => 'Ramos']);

        TenantMembership::factory()->withPin('7788')->create([
            'user_id' => $gerente->id,
            'employee_code' => 'G900',
            'has_all_branches' => true,
        ]);

        // El permiso sale de la UNIÓN de sus roles (ADR-008), no de un rol activo: el autorizador no está operando
        // el sistema, está poniendo su PIN en la terminal de otra persona.
        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->firstOrFail();
        $gerente->syncRoles([$rol]);

        return app(PinAuthorizationService::class)
            ->grant('G900', '7788', 'inventory.waste.authorize_above_threshold')
            ->token;
    });
}

// ------------------------------------------------------------ Catálogo de motivos

it('administra el catálogo de motivos del negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste-reasons', ['name' => 'Se pasó de cocción', 'requires_evidence' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Se pasó de cocción')
        ->assertJsonPath('data.requires_evidence', true)
        ->assertJsonPath('data.is_active', true);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/waste-reasons')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('no admite dos motivos con el mismo nombre', function () {
    // Dos motivos iguales volverían ambiguo cualquier reporte agrupado por motivo, que es la única razón por la que
    // el catálogo existe.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste-reasons', ['name' => 'Se cayó al piso'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

it('un motivo dado de baja no se puede usar, pero sigue existiendo', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/waste-reasons/{$this->motivo->ulid}", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // No se puede capturar con él: un cliente con el selector en caché seguiría usando un motivo retirado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma())
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['waste_reason_ulid']]);

    // Y sigue apareciendo si se piden todos: los movimientos que lo citan tienen que poder seguir explicándose.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/waste-reasons?only_active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// -------------------------------------------------------------------- Registro

it('registra una merma con su motivo y descuenta existencia', function () {
    conCosto('0.0320');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma(['notes' => 'Se rompió la caja al bajarla']))
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'waste')
        ->assertJsonPath('data.0.direction', 'out')
        ->assertJsonPath('data.0.waste_reason.name', 'Se cayó al piso')
        // Valuada al costo vigente (D152): 100 × 0.0320.
        ->assertJsonPath('data.0.total_cost', '3.20')
        ->assertJsonPath('data.0.balance_after', '-100.0000');
});

it('EXIGE motivo: una merma sin él no se puede investigar', function () {
    $sinMotivo = merma();
    unset($sinMotivo['waste_reason_ulid']);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', $sinMotivo)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['waste_reason_ulid']]);
});

it('deja entrada en la bitácora técnica, además del kardex', function () {
    conCosto('0.0320');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma())
        ->assertCreated();

    app(TenantContext::class)->set($this->tenant->id);

    // De todos los movimientos de inventario, la merma es el único que va a la bitácora: es una PÉRDIDA con actor,
    // la zona de robo hormiga que §9 pide poder investigar (§6.7). El resto tiene su evidencia en el kardex, y
    // registrarlos aquí produciría una bitácora que nadie puede leer.
    $asiento = AuditEntry::query()->where('action', AuditAction::WASTE_REGISTERED)->sole();

    expect($asiento->after['reason'])->toBe('Se cayó al piso')
        ->and($asiento->after['estimated_value'])->toBe('3.20')
        // Sin autorización: no hacía falta, no se inventa un autorizador.
        ->and($asiento->authorized_by_membership_id)->toBeNull();
});

// ---------------------------------------------------------------------- Umbral

it('una merma bajo el umbral NO pide autorización', function () {
    // El umbral por omisión son $500. Cien gramos a tres centavos no se acercan.
    conCosto('0.0320');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma())
        ->assertCreated();
});

it('una merma SOBRE el umbral se rechaza con 409 y dice qué falta', function () {
    // Azafrán: cien gramos a $80 el gramo son $8 000.
    conCosto('80.0000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma())
        ->assertStatus(409);

    // 409 y no 422, y la diferencia importa: no hay nada en el cuerpo que corregir. Los datos son correctos y la
    // operación es legítima — lo que falta es que otra persona autorice. Un 422 mandaría al usuario a revisar los
    // campos, que es el sitio equivocado.
    expect($respuesta->json('type'))->toBe('authorization_required')
        ->and($respuesta->json('required_permission'))->toBe('inventory.waste.authorize_above_threshold')
        ->and($respuesta->json('title'))->toContain('8000.00')
        ->and($respuesta->json('title'))->toContain('500.00');

    // Y NO se movió existencia: la decisión de autorizar se toma ANTES de tocar el kardex, porque una merma
    // registrada y después rechazada dejaría existencia descontada sin quien responda por ella.
    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): int => StockMovement::query()->count(),
    ))->toBe(0);
});

it('con la autorización de un gerente, la merma sobre el umbral procede', function () {
    conCosto('80.0000');

    $token = concesionDeGerente();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma(['authorization_token' => $token]))
        ->assertCreated()
        ->assertJsonPath('data.0.total_cost', '8000.00');

    app(TenantContext::class)->set($this->tenant->id);

    // La columna que distingue «lo hizo el gerente» de «el gerente autorizó que lo hiciera otra persona». Es la
    // que hace posible el reporte de robo hormiga de §9.
    $asiento = AuditEntry::query()->where('action', AuditAction::WASTE_REGISTERED)->sole();

    $autorizador = TenantMembership::query()->whereKey($asiento->authorized_by_membership_id)->sole();

    expect($autorizador->employee_code)->toBe('G900')
        ->and($asiento->actor_membership_id)->not->toBe($autorizador->id);
});

it('la autorización es de UN SOLO USO', function () {
    conCosto('80.0000');

    $token = concesionDeGerente();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma(['authorization_token' => $token]))
        ->assertCreated();

    // Reusarla sería poder mermar el almacén entero con un solo PIN. `consume` lee y borra en una operación, así
    // que no hay ventana entre validar e invalidar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma(['authorization_token' => $token]))
        ->assertStatus(422);
});

it('el umbral se puede ajustar POR SUCURSAL', function () {
    conCosto('80.0000');

    // El volumen de un bar y de una fonda no se parecen: un umbral que sirve en uno vuelve el otro impracticable.
    app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(Settings::class)->setForBranch(
            'inventory.waste_authorization_threshold',
            $this->branch->id,
            20000,
        ),
    );

    // Los mismos $8 000 que antes exigían autorización, ahora no.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma())
        ->assertCreated();
});

it('un artículo SIN costo no puede cruzar el umbral', function () {
    // Su merma no vale nada calculable. Se registra sin autorización, que es la alternativa correcta a inventarle
    // un costo —un cero diría que la mercancía es gratis— o a bloquear la merma por un dato que le falta a otro
    // módulo, dejando al almacén sin poder operar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma(['quantity' => '999999']))
        ->assertCreated()
        ->assertJsonPath('data.0.unit_cost', null)
        ->assertJsonPath('data.0.total_cost', null);
});

// ----------------------------------------------------------------------- FEFO

it('la merma de un artículo con lotes se parte por FEFO', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $ml = Unit::query()->where('code', 'ml')->firstOrFail();

    $leche = Article::create([
        'name' => 'Leche',
        'base_unit_id' => $ml->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    $registro = app(RecordStockMovement::class);

    foreach ([['L-MAR', now()->addMonth(), '300.0000'], ['L-ABR', now()->addMonths(2), '300.0000']] as [$code, $exp, $qty]) {
        $lote = ArticleLot::create([
            'article_id' => $leche->id,
            'code' => $code,
            'expires_at' => $exp->toDateString(),
            'received_at' => now()->subDay()->toDateString(),
        ]);

        $registro->record(
            warehouse: $this->warehouse,
            article: $leche,
            kind: StockMovementKind::PurchaseReceipt,
            quantity: $qty,
            lot: $lote,
        );
    }

    app(TenantContext::class)->forget();

    // 500 no caben en el lote de marzo: la merma se parte, y cada renglón dice de qué partida física se perdió —
    // que es exactamente lo que se necesita cuando la causa es un lote defectuoso.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/waste', merma([
            'article_ulid' => $leche->ulid,
            'quantity' => '500',
        ]))
        ->assertCreated()
        ->assertJsonCount(2, 'data');

    expect(array_column($respuesta->json('data'), 'quantity'))->toBe(['300.0000', '200.0000']);

    // Los dos renglones llevan el mismo motivo.
    foreach ($respuesta->json('data') as $movimiento) {
        expect($movimiento['waste_reason']['name'])->toBe('Se cayó al piso');
    }

    // Y el lote de marzo quedó en cero, no en negativo.
    expect(app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): string => ArticleStock::query()
            ->whereHas('lot', fn ($q) => $q->where('code', 'L-MAR'))
            ->value('quantity'),
    ))->toBe('0.0000');
});

// ---------------------------------------------------------------- Autorización

it('el almacenista registra mermas y NO puede autorizarlas', function () {
    // La regla que el umbral defiende: si quien registra pudiera autorizar, el umbral no defendería nada. Es la
    // misma razón por la que nadie edita sus propios roles.
    conCosto('80.0000');

    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $this->owner->syncRoles([$almacenista]);
    app(TenantContext::class)->forget();

    // Registra sin problema mientras esté bajo el umbral…
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson('/api/v1/waste', merma(['quantity' => '1']))
        ->assertCreated();

    // …y sobre el umbral necesita a alguien más.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson('/api/v1/waste', merma())
        ->assertStatus(409);
});

it('el mesero no registra mermas', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson('/api/v1/waste', merma())
        ->assertForbidden();
});
