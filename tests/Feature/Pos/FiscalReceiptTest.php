<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Finance\Infrastructure\Models\PaymentMethod;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosTicket;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL SNAPSHOT FISCAL EN EL TICKET, AL COBRAR (Iteración 6, D317, ADR-005)
 *
 * Si el cliente pide factura, al cobrar se elige uno de sus perfiles fiscales y el ticket final CONGELA ese snapshot
 * —RFC, razón social, régimen, uso, CP—, como todo en el POS. Sin cliente o sin selección, es «público en general».
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
    $this->membershipId = $alta['membership']->id;

    app(TenantContext::class)->set($this->tenant->id);

    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $this->articulo = Article::create([
        'name' => 'Comida corrida',
        'category_id' => ArticleCategory::create(['name' => 'Alimentos', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);
    $this->efectivo = PaymentMethod::query()->where('code', 'CASH')->sole()->ulid;

    $this->cliente = Customer::create(['name' => 'Facturador', 'created_by_membership_id' => $this->membershipId]);
    $this->perfil = $this->cliente->fiscalProfiles()->create([
        'rfc' => 'ABC010101AB1',
        'person_type' => 'moral',
        'business_name' => 'Empresa Cliente SA de CV',
        'postal_code' => '06700',
        'tax_regime_code' => '601',
        'cfdi_use_code' => 'G03',
        'is_default' => true,
    ]);

    app(TenantContext::class)->forget();

    /** Abre caja y una cuenta atada al cliente; captura un artículo de 100; devuelve el ULID de la cuenta. */
    $this->cuentaDelCliente = function (): string {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $this->terminal->ulid, 'opening_float' => '0.00'])
            ->assertCreated();

        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        // Se ata el cliente por modelo (la asociación desde el POS es otra superficie).
        app(TenantContext::class)->runFor($this->tenant->id, function () use ($cuenta): void {
            PosAccount::query()->where('ulid', $cuenta)->update(['customer_id' => $this->cliente->id]);
        });

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $this->articulo->ulid, 'quantity' => '1']],
            ])
            ->assertCreated();

        return $cuenta;
    };
});

afterEach(fn () => app(TenantContext::class)->forget());

it('cobrar con perfil fiscal congela el snapshot en el ticket', function () {
    $cuenta = ($this->cuentaDelCliente)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo, 'amount' => '100.00', 'tendered_amount' => '100.00']],
            'fiscal_profile_ulid' => $this->perfil->ulid,
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    $ticket = PosTicket::query()->where('kind', 'final_receipt')->sole();

    expect($ticket->fiscal_rfc)->toBe('ABC010101AB1');
    expect($ticket->fiscal_business_name)->toBe('Empresa Cliente SA de CV');
    expect($ticket->fiscal_tax_regime_code)->toBe('601');
    expect($ticket->fiscal_cfdi_use_code)->toBe('G03');
    expect($ticket->fiscal_postal_code)->toBe('06700');
});

it('cobrar sin perfil deja el ticket como público en general', function () {
    $cuenta = ($this->cuentaDelCliente)();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo, 'amount' => '100.00', 'tendered_amount' => '100.00']],
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(PosTicket::query()->where('kind', 'final_receipt')->sole()->fiscal_rfc)->toBeNull();
});

it('no se puede facturar con el perfil de OTRO cliente', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $otro = Customer::create(['name' => 'Otro', 'created_by_membership_id' => $this->membershipId]);
    $perfilAjeno = $otro->fiscalProfiles()->create([
        'rfc' => 'XYZ020202CD2',
        'person_type' => 'moral',
        'business_name' => 'Otra Empresa',
        'postal_code' => '06700',
        'tax_regime_code' => '601',
        'cfdi_use_code' => 'G03',
    ]);
    app(TenantContext::class)->forget();

    $cuenta = ($this->cuentaDelCliente)();

    // El perfil es de otro cliente: el cobro se rechaza entero antes de tocar dinero.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/payments", [
            'payments' => [['payment_method_ulid' => $this->efectivo, 'amount' => '100.00', 'tendered_amount' => '100.00']],
            'fiscal_profile_ulid' => $perfilAjeno->ulid,
        ])
        ->assertStatus(409);

    // Y no quedó ticket final: la cuenta sigue sin cobrar.
    app(TenantContext::class)->set($this->tenant->id);
    expect(PosTicket::query()->where('kind', 'final_receipt')->count())->toBe(0);
});
