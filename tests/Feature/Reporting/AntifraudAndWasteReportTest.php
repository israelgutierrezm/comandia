<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Pos\Infrastructure\Models\PosAccount;
use App\Modules\Pos\Infrastructure\Models\PosDiscount;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * LOS REPORTES DE ANTIFRAUDE Y MERMAS SOBRE EL MOTOR (Iteración 7, §9, §6.2)
 *
 * Prueban que dos definiciones más, registradas por sus dueños (Pos e Inventory), corren por el mismo motor genérico:
 * el antifraude agrupa descuentos manuales por quién los autorizó; las mermas agrupan el kardex por motivo.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Remedios',
        ownerPaternalSurname: 'Vargas',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];
    $this->membershipId = $alta['membership']->id;
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el antifraude agrupa los descuentos manuales por quién los autorizó', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $cuenta = PosAccount::create([
            'branch_id' => $this->branch->id,
            'series' => 'A', 'folio' => 1, 'kind' => 'dine_in', 'status' => 'paid',
            'waiter_membership_id' => $this->membershipId,
            'opened_by_membership_id' => $this->membershipId,
            'opened_at' => '2026-08-21 10:00:00',
        ]);

        // Un descuento MANUAL de $50, autorizado por el dueño con su PIN.
        PosDiscount::create([
            'pos_account_id' => $cuenta->id,
            'kind' => 'amount', 'source' => 'manual',
            'value' => '50.00', 'resulting_amount' => '50.00',
            'reason' => 'Cliente frecuente',
            'applied_by_membership_id' => $this->membershipId,
            'authorized_by_membership_id' => $this->membershipId,
        ]);
    });

    $fila = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/antifraud.discounts')
        ->assertOk()
        ->json('data.rows.0');

    expect($fila['authorizer'])->toBe('Remedios Vargas');
    expect($fila['amount'])->toBe('50.00');
    expect($fila['times'])->toBe(1);
});

it('las mermas se agrupan por motivo, con su cantidad y costo', function () {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $almacen = Branch::query()->find($this->branch->id)->default_warehouse_id;
        $motivo = WasteReason::create(['name' => 'Caducidad']);
        $articulo = Article::create([
            'name' => 'Leche',
            'category_id' => ArticleCategory::create(['name' => 'Lácteos', 'level' => 1])->id,
            'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        ]);

        StockMovement::create([
            'warehouse_id' => $almacen,
            'article_id' => $articulo->id,
            'waste_reason_id' => $motivo->id,
            'kind' => 'waste', 'direction' => 'out',
            'quantity' => '3.0000', 'unit_cost' => '20.0000', 'total_cost' => '60.00',
            'balance_after' => '0.0000',
            'idempotency_key' => 'test-waste-1',
            'actor_membership_id' => $this->membershipId,
            'occurred_at' => '2026-08-21 12:00:00',
        ]);
    });

    $fila = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/inventory.waste')
        ->assertOk()
        ->json('data.rows.0');

    expect($fila['reason'])->toBe('Caducidad');
    expect($fila['quantity'])->toBe('3.0000');
    expect($fila['cost'])->toBe('60.00');
});
