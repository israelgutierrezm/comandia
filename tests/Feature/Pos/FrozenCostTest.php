<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Pos\Infrastructure\Models\PosOrderItem;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL COSTO DEL MOMENTO SE CONGELA EN LA VENTA (Iteración 7, D322)
 *
 * ## Lo que estas pruebas fijan
 *
 * Que al capturar una línea el POS congela `unit_cost` con el costo vigente de ese instante (leído de Costing por la sonda
 * del kernel `ProductCostProbe`); que un cambio de costo POSTERIOR no reescribe la línea ya capturada (por eso el reporte
 * de margen es fiel al histórico, D320); y que un artículo sin costo conocido congela `0` sin bloquear la venta (§6).
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
    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $this->categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => $this->categoria->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '100.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->forget();

    /** Fija el costo vigente del artículo por el servicio público de Costing (actualiza la proyección en el acto). */
    $this->fijarCosto = fn (Article $articulo, string $costo) => app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => app(CaptureArticleCost::class)->atUnitCost($articulo, $costo),
    );

    /** Abre caja, abre cuenta de barra y captura una unidad; devuelve el ULID de la cuenta. */
    $this->capturar = function (string $articleUlid): string {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $this->terminal->ulid, 'opening_float' => '0.00'])
            ->assertCreated();

        $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $articleUlid, 'quantity' => '1']],
            ])
            ->assertCreated();

        return $cuenta;
    };

    $this->costoDeLaLinea = fn (): string => (string) app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn () => PosOrderItem::query()->where('article_id', $this->cerveza->id)->latest('id')->firstOrFail()->unit_cost,
    );
});

afterEach(fn () => app(TenantContext::class)->forget());

it('la venta congela el costo vigente del momento', function () {
    ($this->fijarCosto)($this->cerveza, '42.5000');

    ($this->capturar)($this->cerveza->ulid);

    expect(($this->costoDeLaLinea)())->toBe('42.5000');
});

it('un cambio de costo posterior no altera la línea ya capturada', function () {
    ($this->fijarCosto)($this->cerveza, '42.5000');
    ($this->capturar)($this->cerveza->ulid);

    // El costo sube DESPUÉS de la venta.
    ($this->fijarCosto)($this->cerveza, '99.0000');

    // La línea ya capturada conserva el costo del momento: el margen histórico no se reescribe (D320).
    expect(($this->costoDeLaLinea)())->toBe('42.5000');
});

it('un artículo sin costo conocido congela cero y no bloquea la venta', function () {
    // Nunca se fijó costo: la sonda devuelve "0" y la venta procede igual (§6).
    ($this->capturar)($this->cerveza->ulid);

    expect(($this->costoDeLaLinea)())->toBe('0.0000');
});
