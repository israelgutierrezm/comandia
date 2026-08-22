<?php

declare(strict_types=1);

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL EXPEDIENTE DEL CLIENTE: PERFILES FISCALES Y DIRECCIONES (Iteración 6, §6.6, ADR-005)
 *
 * ## Lo que estas pruebas fijan
 *
 * Que la captura CFDI valida contra los catálogos oficiales (no texto libre); que el régimen tiene que ser compatible
 * con el tipo de persona del RFC; que a lo sumo hay un predeterminado por cliente; y que estas rutas —cuyos permisos
 * llevaban dos iteraciones declarados sin proteger nada— existen y funcionan.
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

    app(TenantContext::class)->set($this->tenant->id);
    $this->cliente = Customer::create(['name' => 'Cliente Uno', 'created_by_membership_id' => $this->membershipId]);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el catálogo del SAT se sirve para los desplegables', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/sat-catalogs')
        ->assertOk();

    // Contiene el RESICO (626) y el uso «gastos en general» (G03), que son los más comunes.
    $regimes = collect($respuesta->json('data.tax_regimes'))->pluck('code')->all();
    $uses = collect($respuesta->json('data.cfdi_uses'))->pluck('code')->all();

    expect($regimes)->toContain('626')->toContain('601')->toContain('605');
    expect($uses)->toContain('G03')->toContain('S01');
});

it('crea un perfil fiscal validando contra el catálogo, y deriva el tipo de persona', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles", [
            'rfc' => 'ABCD010101AB1', // 13 = persona física
            'business_name' => 'Juan Pérez',
            'postal_code' => '06700',
            'tax_regime_code' => '612', // Actividades empresariales y profesionales (física)
            'cfdi_use_code' => 'G03',
            'is_default' => true,
        ])
        ->assertCreated();

    $respuesta->assertJsonPath('data.person_type', 'fisica');
    $respuesta->assertJsonPath('data.rfc', 'ABCD010101AB1');
    $respuesta->assertJsonPath('data.is_default', true);
});

it('rechaza un régimen incompatible con el tipo de persona del RFC', function () {
    // RFC de 13 (física) con un régimen SÓLO de personas morales (601).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles", [
            'rfc' => 'ABCD010101AB1',
            'business_name' => 'Juan Pérez',
            'postal_code' => '06700',
            'tax_regime_code' => '601',
            'cfdi_use_code' => 'G03',
        ])
        ->assertStatus(422);
});

it('rechaza un RFC mal formado y un uso CFDI inventado', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles", [
            'rfc' => 'NOESUNRFC',
            'business_name' => 'X',
            'postal_code' => '06700',
            'tax_regime_code' => '612',
            'cfdi_use_code' => 'ZZZ',
        ])
        ->assertStatus(422);
});

it('sólo hay un perfil fiscal predeterminado por cliente', function () {
    $primero = fn (string $rfc) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles", [
            'rfc' => $rfc,
            'business_name' => 'Empresa',
            'postal_code' => '06700',
            'tax_regime_code' => '601', // moral
            'cfdi_use_code' => 'G03',
            'is_default' => true,
        ])->assertCreated();

    $primero('ABC010101AB1'); // 12 = moral
    $primero('XYZ020202CD2'); // otro moral, también predeterminado

    // El segundo se lleva el «predeterminado»; el primero deja de serlo. Uno solo, siempre.
    $lista = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles")
        ->assertOk()
        ->json('data');

    expect(collect($lista)->where('is_default', true)->count())->toBe(1);
    expect(collect($lista)->firstWhere('is_default', true)['rfc'])->toBe('XYZ020202CD2');
});

it('crea una dirección con sus columnas mexicanas', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/addresses", [
            'label' => 'Casa',
            'street' => 'Av. Siempre Viva',
            'exterior_number' => '742',
            'neighborhood' => 'Springfield',
            'municipality' => 'Cuauhtémoc',
            'state' => 'CDMX',
            'postal_code' => '06700',
            'is_default' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.street', 'Av. Siempre Viva')
        ->assertJsonPath('data.country', 'MX')
        ->assertJsonPath('data.is_default', true);
});

it('un perfil fiscal se edita y se elimina', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles", [
            'rfc' => 'ABC010101AB1',
            'business_name' => 'Empresa',
            'postal_code' => '06700',
            'tax_regime_code' => '601',
            'cfdi_use_code' => 'G03',
        ])->assertCreated()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles/{$ulid}", [
            'rfc' => 'ABC010101AB1',
            'business_name' => 'Empresa Renombrada',
            'postal_code' => '06700',
            'tax_regime_code' => '601',
            'cfdi_use_code' => 'G03',
        ])
        ->assertOk()
        ->assertJsonPath('data.business_name', 'Empresa Renombrada');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('una dirección se edita y se elimina', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/customers/{$this->cliente->ulid}/addresses", [
            'street' => 'Calle 1', 'exterior_number' => '10',
            'neighborhood' => 'Centro', 'municipality' => 'Cuauhtémoc', 'state' => 'CDMX', 'postal_code' => '06000',
        ])->assertCreated()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/customers/{$this->cliente->ulid}/addresses/{$ulid}", [
            'street' => 'Calle 2', 'exterior_number' => '20',
            'neighborhood' => 'Centro', 'municipality' => 'Cuauhtémoc', 'state' => 'CDMX', 'postal_code' => '06000',
        ])
        ->assertOk()
        ->assertJsonPath('data.street', 'Calle 2');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/customers/{$this->cliente->ulid}/addresses/{$ulid}")
        ->assertNoContent();
});

it('el perfil fiscal de un cliente no es alcanzable desde otro negocio', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $this->cliente->fiscalProfiles()->create([
        'rfc' => 'ABC010101AB1',
        'person_type' => 'moral',
        'business_name' => 'Empresa',
        'postal_code' => '06700',
        'tax_regime_code' => '601',
        'cfdi_use_code' => 'G03',
    ]);
    app(TenantContext::class)->forget();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    // El cliente es de otro negocio: el binding por tenant no lo encuentra → 404.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/customers/{$this->cliente->ulid}/fiscal-profiles")
        ->assertNotFound();
});
