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
use App\Modules\Inventory\Domain\Enums\StockCountStatus;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * CONTEOS FÍSICOS: CONGELAR, CONTAR A CIEGAS, CERRAR CON FIRMA (D24, §6.2)
 *
 * El flujo de §6.2 es **conteo → diferencia → ajuste masivo auditado**, y lo que estas pruebas cuidan es cada una
 * de las cuatro cosas que pueden salir mal:
 *
 *   1. Que lo esperado **se congele**. Si se releyera al cerrar, el resultado del conteo dependería de cuánto tardó
 *      quien contaba.
 *   2. Que **no contar no sea contar cero**. Un `NULL` tratado como cero borraría medio almacén.
 *   3. Que el capturista **no vea lo esperado**. Si lo ve, escribe ese número y el conteo no verifica nada.
 *   4. Que un cierre grande **espere una firma**, y que no se aplique nada mientras espera.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que cuenta',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Rosario',
        ownerPaternalSurname: 'Vega',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $kilo = Unit::query()->where('code', 'kg')->firstOrFail();

    $this->arroz = Article::create([
        'name' => 'Arroz',
        'base_unit_id' => $kilo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $this->frijol = Article::create([
        'name' => 'Frijol',
        'base_unit_id' => $kilo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Mete existencia sin pasar por HTTP, para que la prueba hable del conteo y no de la entrada. */
function existencia(Article $article, string $quantity, ?string $unitCost = null): void
{
    app(TenantContext::class)->runFor(test()->tenant->id, function () use ($article, $quantity, $unitCost): void {
        if ($unitCost !== null) {
            app(CaptureArticleCost::class)->atUnitCost($article, $unitCost);
        }

        app(RecordStockMovement::class)->record(
            warehouse: test()->warehouse,
            article: $article,
            kind: StockMovementKind::PurchaseReceipt,
            quantity: $quantity,
        );
    });
}

/** Abre un conteo por HTTP y devuelve su ULID. */
function abrirConteo(array $cuerpo = []): string
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson('/api/v1/stock-counts', array_merge([
            'warehouse_ulid' => test()->warehouse->ulid,
        ], $cuerpo))
        ->assertCreated()
        ->json('data.ulid');
}

/** El almacenista, que cuenta y no cierra. */
function comoAlmacenista(): Role
{
    return app(TenantContext::class)->runFor(test()->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
        test()->owner->syncRoles([$rol]);

        return $rol;
    });
}

/** Concesión de PIN del PROPIETARIO, que es quien puede autorizar un cierre grande. */
function concesionDelPropietario(): string
{
    return app(TenantContext::class)->runFor(test()->tenant->id, function (): string {
        $duena = User::factory()->create(['first_name' => 'Rosario', 'paternal_surname' => 'Vega']);

        TenantMembership::factory()->withPin('1357')->create([
            'user_id' => $duena->id,
            'employee_code' => 'D900',
            'has_all_branches' => true,
        ]);

        $rol = Role::query()->where('name', RoleTemplates::OWNER)->firstOrFail();
        $duena->syncRoles([$rol]);

        return app(PinAuthorizationService::class)
            ->grant('D900', '1357', 'inventory.counts.authorize_above_threshold')
            ->token;
    });
}

// ------------------------------------------------------------------- Apertura

it('abrir un conteo congela una línea por cada saldo del almacén', function () {
    existencia($this->arroz, '40', '30.0000');
    existencia($this->frijol, '25', '22.0000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-counts', ['warehouse_ulid' => $this->warehouse->ulid])
        ->assertCreated()
        ->assertJsonPath('data.status', 'counting')
        ->assertJsonPath('data.is_open', true)
        ->assertJsonCount(2, 'data.lines');

    // El propietario SÍ ve lo esperado: tiene `counts.close`.
    $esperados = array_column($respuesta->json('data.lines'), 'expected_quantity');
    sort($esperados);

    expect($esperados)->toBe(['25.0000', '40.0000']);

    // Y nada está contado todavía. `null`, no cero: son cosas distintas al cerrar.
    foreach ($respuesta->json('data.lines') as $linea) {
        expect($linea['counted_quantity'])->toBeNull()
            ->and($linea['was_counted'])->toBeFalse();
    }
});

it('un conteo cíclico incluye los artículos pedidos aunque no tengan saldo', function () {
    existencia($this->arroz, '40', '30.0000');

    // Se pide contar el frijol, que nunca ha entrado al almacén. Entra con esperado en cero: se pidió contarlo, y
    // si no hay nada en el estante eso también es un resultado del conteo.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-counts', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulids' => [$this->frijol->ulid],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.article.name', 'Frijol')
        ->assertJsonPath('data.lines.0.expected_quantity', '0.0000');

    expect($respuesta->json('data.lines.0.counted_quantity'))->toBeNull();
});

it('NO deja abrir dos conteos del mismo almacén', function () {
    existencia($this->arroz, '40');

    abrirConteo();

    // Dos conteos abiertos aplicarían la misma diferencia dos veces: los dos congelan 40, el primero cierra con 35
    // y aplica −5, y el segundo vuelve a calcular contra sus 40 congelados y aplica −5 otra vez.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-counts', ['warehouse_ulid' => $this->warehouse->ulid])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'conteo abierto'));
});

it('cancelar libera el almacén para volver a contarlo', function () {
    existencia($this->arroz, '40');

    $primero = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$primero}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Sin cancelación, un conteo empezado por error dejaría ese almacén sin poder contarse nunca más.
    $segundo = abrirConteo();

    expect($segundo)->not->toBe($primero);
});

it('un almacén sin nada registrado no se puede contar en general', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/stock-counts', ['warehouse_ulid' => $this->warehouse->ulid])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'No hay nada que contar'));
});

it('lista los conteos del negocio, filtrables por estado y almacén', function () {
    // Esta prueba existe porque me faltaba. La única llamada al listado que había esperaba un 403 del mesero, así
    // que el cuerpo del controlador NUNCA se ejecutó — y tenía un método inventado (`ListQuery::for`) que
    // reventaba con 500. Lo encontró el candado de la búsqueda acentuada (D137), que llama a todos los listados;
    // un 403 verifica la ruta y no verifica el controlador.
    existencia($this->arroz, '40', '30.0000');

    $primero = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$primero}/cancel")
        ->assertOk();

    abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/stock-counts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        // Del más reciente al más viejo: es el orden de la pantalla.
        ->assertJsonPath('data.0.status', 'counting');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/stock-counts?status=cancelled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $primero);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/stock-counts?warehouse={$this->warehouse->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Un filtro fuera de la whitelist se rechaza, no se ignora (§8).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/stock-counts?search=arroz')
        ->assertStatus(422);
});

// -------------------------------------------------------------------- Captura

it('captura la hoja completa de una sola petición', function () {
    existencia($this->arroz, '40', '30.0000');
    existencia($this->frijol, '25', '22.0000');

    $ulid = abrirConteo();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [
                ['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37.5'],
                ['article_ulid' => $this->frijol->ulid, 'counted_quantity' => '25'],
            ],
        ])
        ->assertOk();

    $porArticulo = collect($respuesta->json('data.lines'))->keyBy('article.name');

    expect($porArticulo['Arroz']['counted_quantity'])->toBe('37.5000')
        ->and($porArticulo['Arroz']['variance'])->toBe('-2.5000')
        // −2.5 kg a $30: la diferencia valuada al costo congelado.
        ->and($porArticulo['Arroz']['variance_value'])->toBe('-75.00')
        ->and($porArticulo['Frijol']['variance'])->toBe('0.0000');
});

it('contar CERO no es lo mismo que no contar', function () {
    existencia($this->arroz, '40', '30.0000');
    existencia($this->frijol, '25', '22.0000');

    $ulid = abrirConteo();

    // Sólo se cuenta el arroz, y se cuenta cero: se fue al estante y no había nada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '0']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    // El arroz se vació: se contó y no había.
    expect(ArticleStock::query()->where('article_id', $this->arroz->id)->value('quantity'))->toBe('0.0000');

    // El frijol NO se tocó, aunque estaba en la hoja. Si `null` se hubiera tratado como cero, este cierre habría
    // borrado también los 25 kg de frijol que nadie contó — el error que vacía medio almacén.
    expect(ArticleStock::query()->where('article_id', $this->frijol->id)->value('quantity'))->toBe('25.0000');
});

it('capturar puede volver a NULL para deshacer un dedazo', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '3700']],
        ])
        ->assertOk();

    // Sin poder volver a nulo, el dedazo sólo se podría corregir por otro número, y «no lo conté» sería
    // inalcanzable — que es distinto de cero y produce otro ajuste.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => null]],
        ])
        ->assertOk();

    expect($respuesta->json('data.lines.0.counted_quantity'))->toBeNull()
        ->and($respuesta->json('data.lines.0.variance'))->toBeNull();
});

it('capturar un artículo que no estaba en la hoja crea su renglón', function () {
    existencia($this->arroz, '40', '30.0000');

    // Conteo cíclico de sólo el arroz.
    $ulid = abrirConteo(['article_ulids' => [$this->arroz->ulid]]);

    // Y en el estante aparece frijol, que el sistema no sabía que estaba ahí. Casi siempre significa que una
    // recepción se registró en otro almacén, y es información que no se puede perder.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->frijol->ulid, 'counted_quantity' => '12']],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data.lines');

    $frijol = collect($respuesta->json('data.lines'))->firstWhere('article.name', 'Frijol');

    expect($frijol['expected_quantity'])->toBe('0.0000')
        ->and($frijol['counted_quantity'])->toBe('12.0000')
        ->and($frijol['variance'])->toBe('12.0000');
});

it('rechaza una hoja con el mismo artículo dos veces', function () {
    existencia($this->arroz, '40');

    $ulid = abrirConteo();

    // Dos números para la misma cosa no se pueden conciliar solos, y el segundo pisaría al primero en silencio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [
                ['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37'],
                ['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '38'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['lines.1.article_ulid']]);
});

it('no admite cantidades contadas negativas', function () {
    existencia($this->arroz, '40');

    $ulid = abrirConteo();

    // El saldo esperado sí puede ser negativo (§6.2); lo contado no: en un estante no hay menos que nada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '-5']],
        ])
        ->assertStatus(422);
});

// ------------------------------------------------------------- Congelamiento

it('lo esperado se CONGELA: un movimiento durante el conteo no cambia la diferencia', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    // Mientras la gente cuenta, el almacén sigue operando: salen 10 kg a la cocina.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(RecordStockMovement::class)->record(
        warehouse: $this->warehouse,
        article: $this->arroz,
        kind: StockMovementKind::ManualExit,
        quantity: '10',
    ));

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '38']],
        ])
        ->assertOk();

    // Lo esperado sigue siendo 40, no 30. La diferencia es −2 y significa exactamente «entre lo que el sistema
    // creía cuando se imprimió la hoja y lo que había en el estante». Si se releyera el saldo al cerrar, el
    // resultado del conteo dependería de cuánto tardó quien contaba.
    expect($respuesta->json('data.lines.0.expected_quantity'))->toBe('40.0000')
        ->and($respuesta->json('data.lines.0.variance'))->toBe('-2.0000');
});

// --------------------------------------------------------------------- Cierre

it('cerrar aplica las diferencias al kardex y deja el enlace de vuelta', function () {
    existencia($this->arroz, '40', '30.0000');
    existencia($this->frijol, '25', '22.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [
                ['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37'],
                // Sobrante: aparecieron 2 kg de frijol de más.
                ['article_ulid' => $this->frijol->ulid, 'counted_quantity' => '27'],
            ],
        ])
        ->assertOk();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed');

    // El NETO con signo: −3 kg de arroz a $30 son −90, +2 kg de frijol a $22 son +44. Neto −46.
    expect($respuesta->json('data.variance_value'))->toBe('-46.00')
        // Y el BRUTO: 90 + 44 = 134. Es la cifra del control, la que se compara con el umbral — un conteo que se
        // compensa a sí mismo no debe pasar desapercibido.
        ->and($respuesta->json('data.variance_value_absolute'))->toBe('134.00');

    app(TenantContext::class)->set($this->tenant->id);

    expect(ArticleStock::query()->where('article_id', $this->arroz->id)->value('quantity'))->toBe('37.0000')
        ->and(ArticleStock::query()->where('article_id', $this->frijol->id)->value('quantity'))->toBe('27.0000');

    // Dos ajustes por conteo, uno en cada dirección, y los dos con el conteo como documento origen.
    $ajustes = StockMovement::query()
        ->where('kind', StockMovementKind::CountAdjustment->value)
        ->get();

    expect($ajustes)->toHaveCount(2)
        ->and($ajustes->pluck('direction')->map(fn ($d) => $d->value)->sort()->values()->all())
        ->toBe(['in', 'out'])
        ->and($ajustes->pluck('source_type')->unique()->all())->toBe([StockCount::class]);

    // El enlace de vuelta: cada renglón sabe qué movimiento generó. Es lo que hace navegable conteo → kardex y lo
    // que vuelve DETECTABLE un cierre a medias.
    foreach ($respuesta->json('data.lines') as $linea) {
        if ($linea['variance'] !== '0.0000') {
            expect($linea['adjustment_movement_ulid'])->not->toBeNull();
        }
    }
});

it('un conteo cerrado no admite más cambios', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();

    // Corregir un conteo mal hecho es HACER OTRO. Si se pudiera reabrir, las diferencias ya aplicadas al kardex
    // —que es inmutable— se quedarían sin un documento que las explique tal como estaba al aplicarlas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '1']],
        ])
        ->assertStatus(422);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertStatus(422);
});

it('cerrar deja entrada en la bitácora técnica', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = AuditEntry::query()->where('action', AuditAction::STOCK_COUNT_CLOSED)->sole();

    expect($asiento->after['variance_value'])->toBe('-90.00')
        ->and($asiento->after['lines_adjusted'])->toBe(1)
        ->and($asiento->authorized_by_membership_id)->toBeNull();
});

// --------------------------------------------------------------------- Umbral

it('un cierre bajo el umbral no pide autorización', function () {
    // El umbral por omisión son $5 000.
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();
});

it('un cierre SOBRE el umbral se rechaza con 409 y no aplica NADA', function () {
    // 1 000 kg a $30: faltan 400 kg, o sea $12 000.
    existencia($this->arroz, '1000', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '600']],
        ])
        ->assertOk();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertStatus(409);

    expect($respuesta->json('type'))->toBe('authorization_required')
        ->and($respuesta->json('required_permission'))->toBe('inventory.counts.authorize_above_threshold')
        ->and($respuesta->json('title'))->toContain('12000.00');

    app(TenantContext::class)->set($this->tenant->id);

    // NO se aplicó nada: ni un ajuste, ni el cambio de estado. Un cierre que se aplicara y después se rechazara
    // dejaría cientos de ajustes en una tabla inmutable sin nadie que responda por ellos.
    expect(StockMovement::query()->where('kind', StockMovementKind::CountAdjustment->value)->count())->toBe(0)
        ->and(ArticleStock::query()->where('article_id', $this->arroz->id)->value('quantity'))->toBe('1000.0000')
        ->and(StockCount::query()->where('ulid', $ulid)->value('status'))->toBe(StockCountStatus::Counting);
});

it('el umbral se mide en valor ABSOLUTO, no en el neto', function () {
    // Sobrante y faltante que se compensan: +$9 000 de arroz y −$9 000 de frijol. Neto cero, bruto $18 000.
    existencia($this->arroz, '100', '30.0000');
    existencia($this->frijol, '400', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [
                ['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '400'],
                ['article_ulid' => $this->frijol->ulid, 'counted_quantity' => '100'],
            ],
        ])
        ->assertOk();

    // Con el neto, este cierre pasaría sin que nadie lo mirara — y es justo el caso que más urge revisar: un
    // descuadre grande que se compensa a sí mismo casi nunca es azar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertStatus(409)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, '18000.00'));
});

it('con la autorización del propietario, el cierre grande procede', function () {
    existencia($this->arroz, '1000', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '600']],
        ])
        ->assertOk();

    $token = concesionDelPropietario();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close", ['authorization_token' => $token])
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.variance_value', '-12000.00');

    app(TenantContext::class)->set($this->tenant->id);

    $asiento = AuditEntry::query()->where('action', AuditAction::STOCK_COUNT_CLOSED)->sole();

    $autorizador = TenantMembership::query()->whereKey($asiento->authorized_by_membership_id)->sole();

    expect($autorizador->employee_code)->toBe('D900');
});

it('el umbral se puede ajustar por sucursal', function () {
    existencia($this->arroz, '1000', '30.0000');

    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(Settings::class)->setForBranch(
        'inventory.count_authorization_threshold',
        $this->branch->id,
        50000,
    ));

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '600']],
        ])
        ->assertOk();

    // Los mismos $12 000 que antes exigían firma, ahora no.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();
});

it('un artículo sin costo se ajusta igual, pero no suma al umbral', function () {
    // Sin costo capturado. La cantidad es real y se ajusta; el valor no es calculable y no cruza un umbral en pesos
    // — la misma consecuencia que en las mermas (D169), con el mismo argumento.
    existencia($this->arroz, '999999');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '0']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk()
        ->assertJsonPath('data.variance_value', '0.00');

    app(TenantContext::class)->set($this->tenant->id);

    // La cantidad SÍ se movió, aunque no valga pesos.
    expect(ArticleStock::query()->where('article_id', $this->arroz->id)->value('quantity'))->toBe('0.0000');
});

// ------------------------------------------------------------- Conteo ciego

it('el almacenista NO ve lo esperado ni la diferencia', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $rol = comoAlmacenista();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->getJson("/api/v1/stock-counts/{$ulid}")
        ->assertOk();

    $linea = $respuesta->json('data.lines.0');

    // Si leyera «esperado: 40», escribiría 40 y no contaría. El conteo dejaría de verificar nada y se volvería una
    // confirmación de lo que el sistema ya creía — que es lo que §6.2 quiere reconciliar.
    //
    // Es el mismo control que §6.3 ya aplica al efectivo con el precorte ciego.
    expect($linea)->not->toHaveKey('expected_quantity')
        ->and($linea)->not->toHaveKey('variance')
        ->and($linea)->not->toHaveKey('variance_value')
        // Lo que sí ve: lo que él mismo capturó.
        ->and($linea)->toHaveKey('counted_quantity');

    // Y tampoco el total, que sería la misma pista con otro nombre.
    expect($respuesta->json('data'))->not->toHaveKey('variance_value');
});

it('el almacenista captura pero no cierra ni cancela', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $rol = comoAlmacenista();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37']],
        ])
        ->assertOk();

    // Quien cuenta no decide que su conteo es la verdad (§6.2).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertForbidden();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->postJson("/api/v1/stock-counts/{$ulid}/cancel")
        ->assertForbidden();
});

it('un conteo CERRADO publica sus cifras a quien puede verlo', function () {
    existencia($this->arroz, '40', '30.0000');

    $ulid = abrirConteo();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/stock-counts/{$ulid}/lines", [
            'lines' => [['article_ulid' => $this->arroz->ulid, 'counted_quantity' => '37']],
        ])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/stock-counts/{$ulid}/close")
        ->assertOk();

    $rol = comoAlmacenista();

    // El secreto sólo tenía sentido mientras se contaba: una vez cerrado, el resultado es información del negocio
    // y esconderlo a quien contó sería mezquino además de inútil.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $rol->ulid)
        ->getJson("/api/v1/stock-counts/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.variance_value', '-90.00');
});

it('el mesero no toca los conteos', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$mesero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/stock-counts')
        ->assertForbidden();
});
