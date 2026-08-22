<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL MOTOR DE REPORTES Y EL REPORTE DE VENTAS POR ARTÍCULO (Iteración 7, §6.7, ADR-006/ADR-007)
 *
 * ## Lo que estas pruebas fijan
 *
 * Que el endpoint genérico ejecuta una definición registrada por su dueño: lista el catálogo, expone la definición para
 * autoconfigurar el frontend, agrega ventas por artículo con su margen (usando el costo congelado), rechaza un parámetro
 * fuera de la whitelist (422), y —lo más importante— NO filtra datos de otro negocio (aislamiento de tenant del motor,
 * que usa selectRaw/group by y por eso es el punto más peligroso para una fuga).
 */
function nuevoNegocio(string $nombre, string $correo): array
{
    $alta = app(ProvisionTenant::class)->provision(
        businessName: $nombre,
        ownerEmail: $correo,
        ownerFirstName: 'Dueño',
        ownerPaternalSurname: 'Prueba',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->set($alta['tenant']->id);
    $categoria = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1]);
    $articulo = Article::create([
        'name' => 'Cerveza',
        'category_id' => $categoria->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '116.00',
        'is_available_in_pos' => true,
    ]);
    $terminal = Terminal::create(['branch_id' => $alta['branch']->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    app(TenantContext::class)->forget();

    return [...$alta, 'categoria' => $categoria, 'articulo' => $articulo, 'terminal' => $terminal];
}

beforeEach(function () {
    $this->negocio = nuevoNegocio('Fonda del Centro', 'ana@fonda.mx');
    $this->tenant = $this->negocio['tenant'];
    $this->owner = $this->negocio['owner'];
    $this->branch = $this->negocio['branch'];

    $this->fijarCosto = fn (array $n, string $costo) => app(TenantContext::class)->runFor(
        $n['tenant']->id,
        fn () => app(CaptureArticleCost::class)->atUnitCost($n['articulo'], $costo),
    );

    /** Abre caja, abre cuenta, captura `qty` del artículo del negocio. */
    $this->vender = function (array $n, string $qty): void {
        $this->actingAsSpa($n['owner'], $n['tenant']->id)
            ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $n['terminal']->ulid, 'opening_float' => '0.00'])
            ->assertCreated();

        $cuenta = $this->actingAsSpa($n['owner'], $n['tenant']->id)
            ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $n['branch']->ulid, 'label' => 'Barra'])
            ->assertCreated()
            ->json('data.ulid');

        $this->actingAsSpa($n['owner'], $n['tenant']->id)
            ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
                'lines' => [['article_ulid' => $n['articulo']->ulid, 'quantity' => $qty]],
            ])
            ->assertCreated();
    };
});

afterEach(fn () => app(TenantContext::class)->forget());

it('el catálogo lista los reportes que el rol puede ver', function () {
    $keys = collect($this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports')
        ->assertOk()
        ->json('data'))->pluck('key')->all();

    // El dueño ve los reportes cuyos permisos tiene; el orden no está garantizado.
    expect($keys)->toContain('sales.by_article');
});

it('la definición trae dimensiones, medidas y filtros para autoconfigurar el frontend', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article/definition')
        ->assertOk();

    expect(collect($respuesta->json('data.dimensions'))->pluck('key')->all())->toContain('article')->toContain('category');
    expect(collect($respuesta->json('data.measures'))->pluck('key')->all())->toContain('net_sales')->toContain('margin_pct');
});

it('agrega ventas por artículo con el margen sobre el costo congelado', function () {
    ($this->fijarCosto)($this->negocio, '40.0000'); // costo 40
    ($this->vender)($this->negocio, '2');            // 2 cervezas de 116 (IVA incluido 16%)

    $fila = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article')
        ->assertOk()
        ->assertJsonPath('data.report', 'sales.by_article')
        ->json('data.rows.0');

    expect($fila['article'])->toBe('Cerveza');
    expect($fila['units'])->toBe('2.0000');
    // Neto = 2 × (116 / 1.16) = 200.00; costo = 2 × 40 = 80.00; margen = (200−80)/200 = 60.00%.
    expect($fila['net_sales'])->toBe('200.00');
    expect($fila['cost'])->toBe('80.00');
    expect($fila['margin_pct'])->toBe('60.00');
});

it('un parámetro fuera de la whitelist se rechaza con 422', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article?inventado=1')
        ->assertStatus(422);
});

it('un reporte inexistente responde 404', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/no.existe')
        ->assertNotFound();
});

it('las ventas de un negocio NO se filtran a otro (aislamiento del motor)', function () {
    // Negocio A vende 2.
    ($this->fijarCosto)($this->negocio, '40.0000');
    ($this->vender)($this->negocio, '2');

    // Negocio B vende 5, de su propio artículo.
    $otro = nuevoNegocio('Café del Norte', 'beto@cafe.mx');
    ($this->fijarCosto)($otro, '10.0000');
    ($this->vender)($otro, '5');

    // El reporte de A ve SÓLO las suyas: una fila, 2 unidades. Las 5 de B no aparecen.
    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/reports/sales.by_article')
        ->assertOk()
        ->json('data.rows');

    expect($filas)->toHaveCount(1);
    expect($filas[0]['units'])->toBe('2.0000');

    // Y el de B ve sólo sus 5.
    $filasB = $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson('/api/v1/reports/sales.by_article')
        ->assertOk()
        ->json('data.rows');

    expect($filasB)->toHaveCount(1);
    expect($filasB[0]['units'])->toBe('5.0000');
});
