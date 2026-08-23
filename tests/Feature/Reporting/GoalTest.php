<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * METAS Y SEMÁFORO (Iteración 7, Tanda C, D46)
 *
 * Prueban que el semáforo compara el valor real del reporte (gran total) contra la meta, con la tolerancia del 10%, y en
 * la dirección correcta; que sin meta dice «no_goal»; y que fijar la meta dos veces la actualiza, no la duplica.
 *
 * La venta produce venta neta = 2 × (116 ÷ 1.16) = 200.00, el valor contra el que se comparan las metas.
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
    $terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '116.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $terminal->ulid, 'opening_float' => '0.00'])->assertCreated();
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])->assertCreated()->json('data.ulid');
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", ['lines' => [['article_ulid' => $cerveza->ulid, 'quantity' => '2']]])->assertCreated();

    $this->fijarMeta = fn (string $target, string $direction = 'higher_better') => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/report-goals', [
            'report_key' => 'sales.by_article',
            'measure_key' => 'net_sales',
            'period' => 'month',
            'target_value' => $target,
            'direction' => $direction,
        ]);

    $this->semaforo = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article/goal-status?measure=net_sales&period=month')
        ->assertOk();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('sin meta, el semáforo reporta el valor y no_goal', function () {
    ($this->semaforo)()
        ->assertJsonPath('data.value', '200.00')
        ->assertJsonPath('data.status', 'no_goal');
});

it('con la meta cumplida, está en meta', function () {
    ($this->fijarMeta)('100')->assertCreated();

    ($this->semaforo)()
        ->assertJsonPath('data.status', 'on_track')
        ->assertJsonPath('data.target', '100.0000');
});

it('cerca de la meta (dentro del 10%), advierte', function () {
    // Meta 210: 200 está por debajo pero dentro del 10% (210 − 21 = 189 ≤ 200).
    ($this->fijarMeta)('210')->assertCreated();

    ($this->semaforo)()->assertJsonPath('data.status', 'warning');
});

it('lejos de la meta, fuera de meta', function () {
    // Meta 300: 200 está por debajo del umbral (300 − 30 = 270 > 200).
    ($this->fijarMeta)('300')->assertCreated();

    ($this->semaforo)()->assertJsonPath('data.status', 'off_track');
});

it('el semáforo del mes ignora ventas de otro periodo', function () {
    // La venta del beforeEach se lleva a hace un año: fuera del mes en curso.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        App\Modules\Pos\Infrastructure\Models\PosOrderItem::query()->update(['created_at' => now()->subYear()]);
    });

    ($this->fijarMeta)('100')->assertCreated();

    // El mes en curso no tiene ventas: el valor es 0 y la meta de 100 queda fuera.
    ($this->semaforo)()
        ->assertJsonPath('data.value', '0')
        ->assertJsonPath('data.status', 'off_track');
});

it('una meta se borra', function () {
    $ulid = ($this->fijarMeta)('100')->assertCreated()->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/report-goals/{$ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/report-goals?report=sales.by_article')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('fijar la meta dos veces la actualiza, no la duplica', function () {
    ($this->fijarMeta)('100')->assertCreated();
    ($this->fijarMeta)('250')->assertOk(); // segundo POST: actualiza (200 OK, no 201)

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/report-goals?report=sales.by_article')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.target_value', '250.0000');

    // Y el semáforo usa la meta nueva: 200 vs 250 (250 − 25 = 225 > 200) → fuera de meta.
    ($this->semaforo)()->assertJsonPath('data.status', 'off_track');
});
