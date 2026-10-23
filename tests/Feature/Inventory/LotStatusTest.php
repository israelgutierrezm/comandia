<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Inventory\Application\IssueStock;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Domain\Enums\LotStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL ESTADO DE UN LOTE SE MANTIENE SOLO (D377)
 *
 * «Agotado» lo ponía nadie y «Caducado» sólo una persona, y mientras tanto la salida automática surtía lo vencido primero.
 * Ahora el kardex marca «Agotado» al dejar un lote en cero y lo reactiva si le vuelve a entrar mercancía, y una tarea de
 * cada noche marca «Caducado» lo vencido. El saldo nunca se toca: dar la mercancía por tirada es de una persona.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería con Lotes',
        ownerEmail: 'dueno@cafe.mx',
        ownerFirstName: 'Mauricio',
        ownerPaternalSurname: 'Leal',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->almacen = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->crema = Article::create([
        'name' => 'Crema para batir',
        'base_unit_id' => Unit::query()->where('code', 'ml')->firstOrFail()->id,
        'is_supply' => true,
        'is_inventoriable' => true,
        'tracks_lots' => true,
    ]);

    /** Crea un lote y le mete existencia en el almacén dado. */
    $this->loteConExistencia = function (string $code, ?string $expiresAt, string $quantity, ?Warehouse $almacen = null): ArticleLot {
        $lote = ArticleLot::create([
            'article_id' => $this->crema->id,
            'code' => $code,
            'expires_at' => $expiresAt,
            'received_at' => now()->subWeek()->toDateString(),
        ]);

        app(RecordStockMovement::class)->record(
            warehouse: $almacen ?? $this->almacen,
            article: $this->crema,
            kind: StockMovementKind::PurchaseReceipt,
            quantity: $quantity,
            lot: $lote,
        );

        return $lote->refresh();
    };

    /** Da salida automática (FEFO) del almacén dado. */
    $this->sacar = fn (string $quantity, ?Warehouse $almacen = null): array => app(IssueStock::class)->issue(
        warehouse: $almacen ?? $this->almacen,
        article: $this->crema,
        kind: StockMovementKind::ManualExit,
        quantity: $quantity,
    );
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('dejar un lote en cero lo marca AGOTADO, y una entrada lo reactiva', function () {
    $lote = ($this->loteConExistencia)('L-1', now()->addMonth()->toDateString(), '1000.0000');

    ($this->sacar)('400.0000');
    expect($lote->refresh()->status)->toBe(LotStatus::Active);

    ($this->sacar)('600.0000');
    expect($lote->refresh()->status)->toBe(LotStatus::Depleted);

    // Una devolución, un ajuste o la misma partida en otra factura: le vuelve a entrar mercancía.
    app(RecordStockMovement::class)->record(
        warehouse: $this->almacen,
        article: $this->crema,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: '250.0000',
        lot: $lote,
    );

    expect($lote->refresh()->status)->toBe(LotStatus::Active);
});

it('un lote en DOS almacenes no se agota porque se vacíe uno', function () {
    // El lote es del artículo, no del almacén (P3): se agota cuando su saldo SUMADO llega a cero.
    $bodega = Warehouse::create([
        'branch_id' => $this->branch->id,
        'kind' => 'branch',
        'code' => 'BODEGA',
        'name' => 'Bodega',
    ]);

    $lote = ($this->loteConExistencia)('L-2', now()->addMonth()->toDateString(), '500.0000');

    app(RecordStockMovement::class)->record(
        warehouse: $bodega,
        article: $this->crema,
        kind: StockMovementKind::PurchaseReceipt,
        quantity: '300.0000',
        lot: $lote,
    );

    ($this->sacar)('500.0000');

    expect($lote->refresh()->status)->toBe(LotStatus::Active);

    ($this->sacar)('300.0000', $bodega);

    expect($lote->refresh()->status)->toBe(LotStatus::Depleted);
});

it('un lote marcado AGOTADO a mano no surte aunque los libros digan que tiene, y su merma no lo revive', function () {
    // «Agotado: ya no surte» es lo que la pantalla ofrece cuando ya no queda nada aunque el kardex diga otra cosa.
    $fantasma = ($this->loteConExistencia)('L-FANTASMA', now()->addMonth()->toDateString(), '200.0000');
    $real = ($this->loteConExistencia)('L-REAL', now()->addMonths(2)->toDateString(), '500.0000');
    $fantasma->update(['status' => LotStatus::Depleted]);

    $movimientos = ($this->sacar)('100.0000');

    expect($movimientos)->toHaveCount(1)
        ->and($movimientos[0]->lot_id)->toBe($real->id);

    // Dar de baja lo que los libros decían: una SALIDA no reactiva un lote.
    app(RecordStockMovement::class)->record(
        warehouse: $this->almacen,
        article: $this->crema,
        kind: StockMovementKind::Waste,
        quantity: '150.0000',
        lot: $fantasma->refresh(),
    );

    expect($fantasma->refresh()->status)->toBe(LotStatus::Depleted);
});

it('un lote CADUCADO no cambia de estado por moverse', function () {
    $lote = ($this->loteConExistencia)('L-3', now()->addMonth()->toDateString(), '100.0000');
    $lote->update(['status' => LotStatus::Expired]);

    // Su merma lo deja en cero: sigue caducado, no «agotado».
    app(RecordStockMovement::class)->record(
        warehouse: $this->almacen,
        article: $this->crema,
        kind: StockMovementKind::Waste,
        quantity: '100.0000',
        lot: $lote,
    );

    expect($lote->refresh()->status)->toBe(LotStatus::Expired);
});

it('la tarea de cada noche marca CADUCADOS los lotes vencidos y no toca su saldo', function () {
    $vencido = ($this->loteConExistencia)('L-VENCIDO', now()->subDays(3)->toDateString(), '200.0000');
    $vigente = ($this->loteConExistencia)('L-VIGENTE', now()->addDays(3)->toDateString(), '200.0000');
    $sinCaducidad = ($this->loteConExistencia)('L-SIN', null, '200.0000');

    // Un lote que se acabó antes de vencer: «ya no hay» y «ya no sirve» son cosas distintas, y éste es lo primero.
    $agotado = ($this->loteConExistencia)('L-AGOTADO', now()->subDays(5)->toDateString(), '0.0001');
    $agotado->update(['status' => LotStatus::Depleted]);

    // Uno que se consumió hasta cero ANTES de D377, cuando nada marcaba «Agotado»: sigue activo y vencido, sin existencia.
    $consumido = ($this->loteConExistencia)('L-CONSUMIDO', now()->subDays(4)->toDateString(), '30.0000');
    app(RecordStockMovement::class)->record(
        warehouse: $this->almacen,
        article: $this->crema,
        kind: StockMovementKind::ManualExit,
        quantity: '30.0000',
        lot: $consumido,
    );
    ArticleLot::query()->whereKey($consumido->id)->update(['status' => LotStatus::Active->value]);

    app(TenantContext::class)->forget();

    $this->artisan('comandia:lots:mark-expired')->assertSuccessful();

    app(TenantContext::class)->set($this->tenant->id);

    expect($vencido->refresh()->status)->toBe(LotStatus::Expired)
        ->and($vigente->refresh()->status)->toBe(LotStatus::Active)
        ->and($sinCaducidad->refresh()->status)->toBe(LotStatus::Active)
        ->and($agotado->refresh()->status)->toBe(LotStatus::Depleted)
        // Sin existencia no espera merma: se marca agotado, no caducado.
        ->and($consumido->refresh()->status)->toBe(LotStatus::Depleted);

    // El saldo del vencido sigue ahí: que se tiró lo decide quien registre la merma.
    expect((string) ArticleStock::query()->where('lot_id', $vencido->id)->value('quantity'))->toBe('200.0000');
});

it('la tarea respeta a cada negocio: con --tenant sólo toca ése', function () {
    $mio = ($this->loteConExistencia)('L-MIO', now()->subDays(3)->toDateString(), '50.0000');

    app(TenantContext::class)->forget();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Panadería de Enfrente',
        ownerEmail: 'panadera@enfrente.mx',
        ownerFirstName: 'Elena',
        ownerPaternalSurname: 'Sosa',
        plainPassword: 'contrasena-larga-2',
    )['tenant'];

    $ajeno = app(TenantContext::class)->runFor($otro->id, function (): ArticleLot {
        $harina = Article::create([
            'name' => 'Harina',
            'base_unit_id' => Unit::query()->where('code', 'g')->firstOrFail()->id,
            'is_supply' => true,
            'is_inventoriable' => true,
            'tracks_lots' => true,
        ]);

        return ArticleLot::create([
            'article_id' => $harina->id,
            'code' => 'H-VIEJA',
            'expires_at' => now()->subDays(3)->toDateString(),
            'received_at' => now()->subMonth()->toDateString(),
        ]);
    });

    $this->artisan('comandia:lots:mark-expired', ['--tenant' => $this->tenant->slug])->assertSuccessful();

    expect(app(TenantContext::class)->runFor($this->tenant->id, fn () => $mio->refresh()->status))->toBe(LotStatus::Expired);
    expect(app(TenantContext::class)->runFor($otro->id, fn () => $ajeno->refresh()->status))->toBe(LotStatus::Active);
});
