<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Application\ResolveTransferInfrastructure;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PRODUCCIÓN (D17, P8)
 *
 * Producir salsa consume jitomate y chile, y genera salsa. Lo que estas pruebas cuidan es que las cantidades salgan de
 * la **receta** y no de quien captura, que el **rendimiento divida** —200 g utilizables al 80 % exigen sacar 250 del
 * estante— y que la orden guarde un snapshot que la explique dentro de un año, cuando la receta ya cambió.
 *
 * La prueba más importante es la del escalado: una receta que rinde 1 L, producida en 500 ml, tiene que consumir la
 * mitad. Es donde se ve que la conversión de unidades no se reimplementó.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que produce',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Beatriz',
        ownerPaternalSurname: 'Márquez',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->warehouse = Warehouse::query()->where('branch_id', $this->branch->id)->sole();

    $this->gramo = Unit::query()->where('code', 'g')->firstOrFail();
    $this->kilo = Unit::query()->where('code', 'kg')->firstOrFail();
    $this->ml = Unit::query()->where('code', 'ml')->firstOrFail();
    $this->litro = Unit::query()->where('code', 'l')->firstOrFail();

    // Los insumos.
    $this->jitomate = Article::create([
        'name' => 'Jitomate',
        'base_unit_id' => $this->gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $this->chile = Article::create([
        'name' => 'Chile de árbol',
        'base_unit_id' => $this->gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    // El producible: salsa, en mililitros.
    $this->salsa = Article::create([
        'name' => 'Salsa roja',
        'base_unit_id' => $this->ml->id,
        'is_producible' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * La receta de la salsa: rinde 1 LITRO con 800 g de jitomate y 20 g de chile.
 *
 * El litro es deliberado: la salsa se mide en mililitros, así que producir obliga a convertir. Una receta y un artículo
 * en la misma unidad no probarían nada del escalado.
 */
function recetaDeSalsa(string $yieldJitomate = '100.00'): void
{
    app(TenantContext::class)->runFor(test()->tenant->id, fn () => app(SaveRecipe::class)->save(
        article: test()->salsa,
        lines: [
            [
                'component_article_id' => test()->jitomate->id,
                'quantity' => '800',
                'unit_id' => test()->gramo->id,
                'yield_percent' => $yieldJitomate,
            ],
            [
                'component_article_id' => test()->chile->id,
                'quantity' => '20',
                'unit_id' => test()->gramo->id,
            ],
        ],
        outputQuantity: '1',
        outputUnitId: test()->litro->id,
    ));
}

/** Mete existencia de un insumo. */
function insumo(Article $article, string $quantity, ?string $unitCost = null, ?ArticleLot $lot = null): void
{
    app(TenantContext::class)->runFor(test()->tenant->id, function () use ($article, $quantity, $unitCost, $lot): void {
        if ($unitCost !== null) {
            app(CaptureArticleCost::class)->atUnitCost($article, $unitCost);
        }

        app(RecordStockMovement::class)->record(
            warehouse: test()->warehouse,
            article: $article,
            kind: StockMovementKind::PurchaseReceipt,
            quantity: $quantity,
            lot: $lot,
        );
    });
}

/** Planea una producción por HTTP y devuelve su ULID. */
function planea(string $quantity, ?Article $article = null): string
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => test()->warehouse->ulid,
            'article_ulid' => ($article ?? test()->salsa)->ulid,
            'planned_quantity' => $quantity,
        ])
        ->assertCreated()
        ->json('data.ulid');
}

/** El saldo de un artículo en el almacén de la prueba. */
function saldoDeArticulo(Article $article): string
{
    return app(TenantContext::class)->runFor(
        test()->tenant->id,
        fn (): string => ArticleStock::query()
            ->where('warehouse_id', test()->warehouse->id)
            ->where('article_id', $article->id)
            ->value('quantity') ?? '0.0000',
    );
}

// ------------------------------------------------------------------ Planeación

it('planear NO mueve inventario, y previsualiza lo que consumiría', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulid' => $this->salsa->ulid,
            'planned_quantity' => '1000',
            'notes' => 'Para el fin de semana',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.planned_quantity', '1000.0000')
        // `null`, no cero: «todavía no se produjo».
        ->assertJsonPath('data.produced_quantity', null)
        // Un borrador no tiene renglones: se congelan al completar, porque la receta que vale es la del momento en
        // que de verdad se produce.
        ->assertJsonCount(0, 'data.lines');

    // En su lugar viaja la previsualización, calculada de la receta vigente y sin persistir nada.
    $previsto = collect($respuesta->json('data.preview'))->keyBy('component.name');

    expect($previsto['Jitomate']['quantity'])->toBe('800.0000')
        ->and($previsto['Chile de árbol']['quantity'])->toBe('20.0000');

    // Y nada se movió.
    expect(saldoDeArticulo($this->jitomate))->toBe('5000.0000')
        ->and(saldoDeArticulo($this->salsa))->toBe('0.0000');
});

it('no deja planear un artículo que no es producible', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulid' => $this->jitomate->ulid,
            'planned_quantity' => '100',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['article_ulid']]);
});

it('no deja planear un producible SIN receta activa', function () {
    // Una producción que sólo genera existencia sin gastar insumos es una entrada manual. El error sale al planear y
    // no al producir: dejar planear algo imposible sólo aplaza el problema al momento de más prisa.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulid' => $this->salsa->ulid,
            'planned_quantity' => '1000',
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'no tiene receta activa'));
});

// --------------------------------------------------------------- El escalado

it('ESCALA por unidad: una receta que rinde 1 L producida en 500 ml consume la mitad', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('500');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.produced_quantity', '500.0000');

    // Ahí está el punto: la receta habla de litros y el kardex de mililitros. Si la conversión se hubiera
    // reimplementado mal, esto consumiría 800 g (una receta entera) o 400 000 g (mil veces).
    $consumido = collect($respuesta->json('data.lines'))->keyBy('component.name');

    expect($consumido['Jitomate']['consumed_quantity'])->toBe('400.0000')
        ->and($consumido['Chile de árbol']['consumed_quantity'])->toBe('10.0000');

    expect(saldoDeArticulo($this->jitomate))->toBe('4600.0000')
        ->and(saldoDeArticulo($this->chile))->toBe('490.0000')
        ->and(saldoDeArticulo($this->salsa))->toBe('500.0000');
});

it('el RENDIMIENTO divide: 800 g al 80 % exigen sacar 1000 del estante', function () {
    // D21: el rendimiento encarece la línea en el costeo y aquí saca más mercancía. Es el mismo divisor aplicado a dos
    // cosas distintas, y las dos son ciertas: si de cada kilo de jitomate sólo sirven 800 g, para tener 800 g
    // utilizables hay que tomar un kilo.
    recetaDeSalsa(yieldJitomate: '80.00');
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    $consumido = collect($respuesta->json('data.lines'))->keyBy('component.name');

    expect($consumido['Jitomate']['consumed_quantity'])->toBe('1000.0000')
        // Y el chile, sin rendimiento declarado, sale tal cual: el divisor no se aplica donde no lo pidieron.
        ->and($consumido['Chile de árbol']['consumed_quantity'])->toBe('20.0000');

    // El snapshot conserva el rendimiento, que es lo que explica POR QUÉ salieron 1000 y no 800.
    expect($consumido['Jitomate']['recipe']['quantity'])->toBe('800.0000')
        ->and($consumido['Jitomate']['recipe']['yield_percent'])->toBe('80.00')
        ->and($consumido['Jitomate']['recipe']['unit_code'])->toBe('g');
});

// ---------------------------------------------------------------- El snapshot

it('la orden guarda un snapshot que la explica aunque la receta cambie después', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    // La receta cambia: ahora la salsa lleva la mitad de jitomate.
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(SaveRecipe::class)->save(
        article: $this->salsa,
        lines: [[
            'component_article_id' => $this->jitomate->id,
            'quantity' => '400',
            'unit_id' => $this->gramo->id,
        ]],
        outputQuantity: '1',
        outputUnitId: $this->litro->id,
    ));

    // La orden vieja sigue diciendo lo que consumió Y por qué. El §2.8 quería lograr esto con una llave a `recipes`,
    // y no alcanzaba: esa tabla es una fila por artículo y mutable, así que la llave apunta a lo que acabo de
    // cambiar. El snapshot son las líneas.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/production-orders/{$ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data.lines');

    $consumido = collect($respuesta->json('data.lines'))->keyBy('component.name');

    expect($consumido['Jitomate']['recipe']['quantity'])->toBe('800.0000')
        ->and($consumido['Jitomate']['consumed_quantity'])->toBe('800.0000')
        // Y el chile sigue en el documento, aunque la receta nueva ya no lo lleve.
        ->and($consumido)->toHaveKey('Chile de árbol');
});

it('congela el costo del producible y el de cada insumo', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    $consumido = collect($respuesta->json('data.lines'))->keyBy('component.name');

    // 800 g a 0.03 son 24.00; 20 g a 0.40 son 8.00.
    expect($consumido['Jitomate']['line_cost'])->toBe('24.00')
        ->and($consumido['Chile de árbol']['line_cost'])->toBe('8.00');

    // Y el producible entró con su costo, que el costeo ya derivaba de la receta: 32 pesos el litro son 0.032 el ml.
    expect($respuesta->json('data.unit_cost_at_production'))->toBe('0.0320')
        ->and($respuesta->json('data.total_cost'))->toBe('32.00');
});

// ------------------------------------------------------------------- El kardex

it('escribe N+1 movimientos, todos con la orden como documento origen', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $movimientos = StockMovement::query()
        ->where('source_type', ProductionOrder::class)
        ->get();

    // Dos salidas y una entrada. Sin el documento origen, un inventario general aparecería en el kardex como
    // movimientos sueltos y «¿de dónde salieron estos mil mililitros?» no tendría respuesta.
    expect($movimientos)->toHaveCount(3)
        ->and($movimientos->where('kind', StockMovementKind::ProductionOut)->count())->toBe(2)
        ->and($movimientos->where('kind', StockMovementKind::ProductionIn)->count())->toBe(1);
});

it('los insumos con lotes se surten por FEFO, y cada partida es un renglón', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $this->jitomate->update(['tracks_lots' => true]);

    $marzo = ArticleLot::create([
        'article_id' => $this->jitomate->id,
        'code' => 'J-MAR',
        'expires_at' => now()->addWeek()->toDateString(),
        'received_at' => now()->subDay()->toDateString(),
    ]);

    $abril = ArticleLot::create([
        'article_id' => $this->jitomate->id,
        'code' => 'J-ABR',
        'expires_at' => now()->addMonth()->toDateString(),
        'received_at' => now()->subDay()->toDateString(),
    ]);

    app(TenantContext::class)->forget();

    recetaDeSalsa();
    insumo($this->jitomate, '500', '0.0300', $marzo);
    insumo($this->jitomate, '500', null, $abril);
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk()
        // Tres renglones: el jitomate se partió en dos lotes, más el chile.
        ->assertJsonCount(3, 'data.lines');

    $jitomate = collect($respuesta->json('data.lines'))
        ->where('component.name', 'Jitomate')
        ->sortBy('lot.code')
        ->values();

    // Caduca primero, sale primero: 500 de marzo y 300 de abril.
    expect($jitomate[0]['lot']['code'])->toBe('J-ABR')
        ->and($jitomate[0]['consumed_quantity'])->toBe('300.0000')
        ->and($jitomate[1]['lot']['code'])->toBe('J-MAR')
        ->and($jitomate[1]['consumed_quantity'])->toBe('500.0000');

    // Y el snapshot de la receta se repite en las dos: la receta pedía 800, y salieron de dos partidas.
    expect($jitomate[0]['recipe']['quantity'])->toBe('800.0000')
        ->and($jitomate[1]['recipe']['quantity'])->toBe('800.0000');
});

// ---------------------------------------------------------------- Cantidades

it('se puede producir MENOS de lo planeado', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    // Se planearon mil mililitros y salieron ochocientos. Sin poder declararlo, o se registra una mentira o no se
    // registra nada — y el consumo se escala a lo que DE VERDAD salió.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete", ['produced_quantity' => '800'])
        ->assertOk()
        ->assertJsonPath('data.planned_quantity', '1000.0000')
        ->assertJsonPath('data.produced_quantity', '800.0000');

    $consumido = collect($respuesta->json('data.lines'))->keyBy('component.name');

    expect($consumido['Jitomate']['consumed_quantity'])->toBe('640.0000')
        ->and(saldoDeArticulo($this->salsa))->toBe('800.0000');
});

it('la producción NO se bloquea por falta de insumos', function () {
    // §6.2: la cocina hizo la salsa —está en la olla— independientemente de lo que el sistema creyera tener.
    // Bloquear no impediría la producción, sólo impediría registrarla, y el inventario se descuadraría sin rastro.
    recetaDeSalsa();
    insumo($this->jitomate, '100', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    // El saldo queda negativo, que es la señal de que el conteo va atrasado — no un error a esconder.
    expect(saldoDeArticulo($this->jitomate))->toBe('-700.0000')
        ->and(saldoDeArticulo($this->salsa))->toBe('1000.0000');
});

// ------------------------------------------------------- Invariantes del ciclo

it('una orden completada no se vuelve a completar ni a cancelar', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    // Sus movimientos ya están en el kardex, que no admite corrección: rehacerla es producir en sentido inverso.
    foreach (['complete', 'cancel'] as $paso) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->postJson("/api/v1/production-orders/{$ulid}/{$paso}")
            ->assertStatus(422);
    }

    // Y NO se consumió dos veces.
    expect(saldoDeArticulo($this->jitomate))->toBe('4200.0000');
});

it('cancelar un borrador no mueve nada', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $ulid = planea('1000');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(saldoDeArticulo($this->jitomate))->toBe('5000.0000');
});

// ------------------------------------------- Componentes que no se inventarían

it('RECHAZA producir si la receta lleva un componente que no se inventaría', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Una «sub-receta de cálculo»: producible para costear, sin existencias. Consumirla dejaría un saldo negativo
    // creciendo para siempre en un artículo que nadie mira.
    $mezcla = Article::create([
        'name' => 'Mezcla de especias',
        'base_unit_id' => $this->gramo->id,
        // Insumo Y producible: es lo que `SaveRecipe` exige de un ingrediente (D16). Lo que NO es, es inventariable.
        'is_supply' => true,
        'is_producible' => true,
        'is_inventoriable' => false,
    ]);

    app(TenantContext::class)->forget();

    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(SaveRecipe::class)->save(
        article: $this->salsa,
        lines: [
            ['component_article_id' => $this->jitomate->id, 'quantity' => '800', 'unit_id' => $this->gramo->id],
            ['component_article_id' => $mezcla->id, 'quantity' => '5', 'unit_id' => $this->gramo->id],
        ],
        outputQuantity: '1',
        outputUnitId: $this->litro->id,
    ));

    // Deuda declarada: la evolución es explotar la sub-receta. En v1 se rechaza con un mensaje que dice cómo
    // arreglarlo, en lugar de consumir existencia fantasma en silencio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulid' => $this->salsa->ulid,
            'planned_quantity' => '1000',
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'no se inventaría'));
});

it('un componente producible que SÍ se inventaría se consume, no se explota', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // La masa es producible Y inventariable: tiene su propia existencia.
    $masa = Article::create([
        'name' => 'Masa',
        'base_unit_id' => $this->gramo->id,
        'is_supply' => true,
        'is_producible' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($masa): void {
        // La masa se hace de jitomate, para que si se explotara se notara.
        app(SaveRecipe::class)->save(
            article: $masa,
            lines: [['component_article_id' => $this->jitomate->id, 'quantity' => '2', 'unit_id' => $this->gramo->id]],
            outputQuantity: '1',
            outputUnitId: $this->gramo->id,
        );

        app(SaveRecipe::class)->save(
            article: $this->salsa,
            lines: [['component_article_id' => $masa->id, 'quantity' => '100', 'unit_id' => $this->gramo->id]],
            outputQuantity: '1',
            outputUnitId: $this->litro->id,
        );
    });

    insumo($masa, '1000', '0.5000');
    insumo($this->jitomate, '5000', '0.0300');

    $ulid = planea('1000');

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk()
        // UN renglón: la masa. No el jitomate con el que se hizo la masa.
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.component.name', 'Masa')
        ->assertJsonPath('data.lines.0.consumed_quantity', '100.0000');

    // 6.00 y no 50.00, que es lo que yo esperaba al escribir la prueba: la masa es PRODUCIBLE, así que el costeo
    // deriva su costo de su propia receta —2 g de jitomate a 0.03 son 0.06 el gramo— y la captura manual de 0.50 no
    // manda. Es D16 funcionando, y la aserción equivocada era mía.
    //
    // Y es la mejor demostración del punto de esta prueba: la masa se valúa por su receta y a la vez se CONSUME
    // entera. Las dos cosas a la vez, que es exactamente la distinción entre costear y producir.
    expect($respuesta->json('data.lines.0.line_cost'))->toBe('6.00');

    // El jitomate NO se tocó. Explotar la receta hacia abajo consumiría dos veces los mismos insumos: la harina de la
    // masa ya se consumió cuando alguien produjo la masa.
    expect(saldoDeArticulo($masa))->toBe('900.0000')
        ->and(saldoDeArticulo($this->jitomate))->toBe('5000.0000');
});

// ---------------------------------------------------------------- Listado

it('lista las órdenes con sus filtros', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $planeada = planea('1000');
    $completada = planea('500');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/production-orders/{$completada}/complete")
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/production-orders')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/production-orders?only_planned=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $planeada);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/production-orders?status=completed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $completada);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/production-orders?article={$this->salsa->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Un listado no calcula previsualizaciones: sería una consulta de recetas por fila.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/production-orders')
        ->assertOk()
        ->assertJsonPath('data.0.preview', null);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/production-orders?search=salsa')
        ->assertStatus(422);
});

// ------------------------------------------------------------- Autorización

it('el almacenista produce; el mesero no', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $mesero = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
    $this->owner->syncRoles([$almacenista, $mesero]);
    app(TenantContext::class)->forget();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $this->warehouse->ulid,
            'article_ulid' => $this->salsa->ulid,
            'planned_quantity' => '1000',
        ])
        ->assertCreated()
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson("/api/v1/production-orders/{$ulid}/complete")
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/production-orders')
        ->assertForbidden();
});

it('el almacén de tránsito no produce', function () {
    recetaDeSalsa();
    insumo($this->jitomate, '5000', '0.0300');
    insumo($this->chile, '500', '0.4000');

    $transito = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Warehouse => app(ResolveTransferInfrastructure::class)->transitWarehouse(),
    );

    // Lo que hay en tránsito es lo que va en camino (D190): producir ahí dejaría mercancía sin dueño.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/production-orders', [
            'warehouse_ulid' => $transito->ulid,
            'article_ulid' => $this->salsa->ulid,
            'planned_quantity' => '1000',
        ])
        ->assertStatus(422);
});
