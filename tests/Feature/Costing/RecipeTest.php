<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\RecipeCycleDetector;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Domain\Exceptions\RecipeCycleException;
use App\Modules\Costing\Domain\Exceptions\RecipeInvariantException;
use App\Modules\Costing\Events\RecipeChanged;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Costing\Infrastructure\Models\RecipeLine;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;

/**
 * RECETAS Y DETECCIÓN DE CICLOS (D16, D21)
 *
 * La detección de ciclos es obligatoria y se hace **antes de escribir**: un ciclo guardado hace que el
 * recálculo de costos no termine nunca, así que descubrirlo en producción significa una cola atascada.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->g = Unit::query()->where('code', 'g')->firstOrFail();
    $this->kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $this->ml = Unit::query()->where('code', 'ml')->firstOrFail();

    // Un caso realista de tres niveles: torta → pan → masa → harina.
    $this->harina = Article::factory()->create(['name' => 'Harina']);
    $this->masa = Article::factory()->producible()->create(['name' => 'Masa']);
    $this->pan = Article::factory()->producible()->create(['name' => 'Pan']);
    $this->torta = Article::factory()->producible()->create(['name' => 'Torta']);

    $this->save = app(SaveRecipe::class);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Encadena masa→harina, pan→masa y torta→pan por el servicio. */
function cadenaDeTresNiveles(): void
{
    $t = test();

    $t->save->save($t->masa, [
        ['component_article_id' => $t->harina->id, 'quantity' => '500.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $t->g->id);

    $t->save->save($t->pan, [
        ['component_article_id' => $t->masa->id, 'quantity' => '300.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '250.0000', outputUnitId: $t->g->id);

    $t->save->save($t->torta, [
        ['component_article_id' => $t->pan->id, 'quantity' => '120.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $t->g->id);
}

it('guarda una receta con su rendimiento', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $recipe = $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $this->g->id);

    // `output_quantity` es lo que hace posible el costeo en cascada: 500 g de harina rinden 600 g de masa.
    expect(bccomp($recipe->output_quantity, '600', 4))->toBe(0);
    expect($recipe->lines)->toHaveCount(1);
});

it('reemplaza la receta completa en lugar de acumular líneas', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal']);

    $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ]);

    $this->save->save($this->masa, [
        ['component_article_id' => $sal->id, 'quantity' => '10.0000', 'unit_id' => $this->g->id],
    ]);

    $recipe = Recipe::query()->where('article_id', $this->masa->id)->firstOrFail();

    // Una sola receta (invariante I1) y una sola línea: la anterior se reemplazó, no se sumó.
    expect(Recipe::query()->where('article_id', $this->masa->id)->count())->toBe(1);
    expect($recipe->lines()->count())->toBe(1);
    expect($recipe->lines()->first()->component_article_id)->toBe($sal->id);
});

// ---------------------------------------------------------------------------
// Detección de ciclos
// ---------------------------------------------------------------------------

it('RECHAZA que un artículo sea ingrediente de sí mismo', function () {
    // El ciclo trivial, y el más probable por error de dedo.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => $this->save->save($this->masa, [
        ['component_article_id' => $this->masa->id, 'quantity' => '1.0000', 'unit_id' => $this->g->id],
    ]))->toThrow(RecipeCycleException::class);
});

it('RECHAZA un ciclo indirecto de tres saltos, con el camino en el mensaje', function () {
    // torta → pan → masa, y ahora se intenta que la masa use la torta.
    // «Se detectó un ciclo» obligaría a buscarlo a mano entre decenas de recetas.
    app(TenantContext::class)->set($this->tenant->id);

    cadenaDeTresNiveles();

    try {
        $this->save->save($this->masa, [
            ['component_article_id' => $this->torta->id, 'quantity' => '1.0000', 'unit_id' => $this->g->id],
        ]);

        $this->fail('Debió rechazar el ciclo.');
    } catch (RecipeCycleException $e) {
        expect($e->getMessage())->toContain('Masa');
        expect($e->getMessage())->toContain('Torta');
        expect($e->getMessage())->toContain('Pan');

        // El camino completo, en orden, empezando y terminando en el artículo que se estaba guardando.
        //
        // Se copia a una variable local: `end()` recibe el arreglo por referencia y mover su puntero
        // interno cuenta como modificar la propiedad, que es `readonly`.
        $path = $e->path;

        expect($path[0])->toBe('Masa');
        expect($path[count($path) - 1])->toBe('Masa');
    }
});

it('un grafo en DIAMANTE se guarda sin problema', function () {
    // El pan y la salsa usan los dos la misma sal. Se llega a la sal por dos caminos y no hay ciclo: es
    // completamente normal en cocina, y un detector mal escrito lo rechazaría.
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal']);
    $salsa = Article::factory()->producible()->create(['name' => 'Salsa']);

    $this->save->save($this->pan, [
        ['component_article_id' => $sal->id, 'quantity' => '5.0000', 'unit_id' => $this->g->id],
    ]);

    $this->save->save($salsa, [
        ['component_article_id' => $sal->id, 'quantity' => '3.0000', 'unit_id' => $this->g->id],
    ]);

    // Y una torta que usa las dos.
    $recipe = $this->save->save($this->torta, [
        ['component_article_id' => $this->pan->id, 'quantity' => '100.0000', 'unit_id' => $this->g->id],
        ['component_article_id' => $salsa->id, 'quantity' => '20.0000', 'unit_id' => $this->g->id],
    ]);

    expect($recipe->lines)->toHaveCount(2);
});

it('el ciclo se evalúa sobre el estado POSTERIOR, no el actual', function () {
    // El caso que una validación línea-por-línea contra el grafo actual respondería mal.
    //
    // Estado: pan → masa. Se reemplaza la receta del pan por "pan → harina", y a la vez se quiere que la
    // masa use el pan. Si se validara contra el grafo viejo, "masa → pan" parecería un ciclo (pan→masa→pan)
    // cuando ya no lo es, porque el pan dejó de usar masa.
    app(TenantContext::class)->set($this->tenant->id);

    $this->save->save($this->pan, [
        ['component_article_id' => $this->masa->id, 'quantity' => '300.0000', 'unit_id' => $this->g->id],
    ]);

    // El pan ya no usa masa.
    $this->save->save($this->pan, [
        ['component_article_id' => $this->harina->id, 'quantity' => '400.0000', 'unit_id' => $this->g->id],
    ]);

    // Así que ahora la masa SÍ puede usar el pan.
    $recipe = $this->save->save($this->masa, [
        ['component_article_id' => $this->pan->id, 'quantity' => '50.0000', 'unit_id' => $this->g->id],
    ]);

    expect($recipe->lines)->toHaveCount(1);
});

it('un ciclo escrito a mano en la base SÍ lo encuentra el detector', function () {
    // Autoverificación del detector. La base nunca contendrá un ciclo porque el servicio lo impide, así
    // que sin escribir uno a mano no habría forma de saber si el detector funciona o si simplemente nunca
    // encuentra nada.
    app(TenantContext::class)->set($this->tenant->id);

    // masa → pan y pan → masa, saltándose el servicio con las factories.
    $recetaMasa = Recipe::factory()->create(['article_id' => $this->masa->id]);
    RecipeLine::factory()->create([
        'recipe_id' => $recetaMasa->id,
        'component_article_id' => $this->pan->id,
    ]);

    $recetaPan = Recipe::factory()->create(['article_id' => $this->pan->id]);
    RecipeLine::factory()->create([
        'recipe_id' => $recetaPan->id,
        'component_article_id' => $this->masa->id,
    ]);

    $graph = app(RecipeCycleDetector::class)->loadGraph();

    expect($graph->findPath($this->masa->id, $this->masa->id))->toBe([$this->masa->id]);
    expect($graph->findPath($this->pan->id, $this->masa->id))->toBe([$this->pan->id, $this->masa->id]);
});

// ---------------------------------------------------------------------------
// Invariantes
// ---------------------------------------------------------------------------

it('RECHAZA una receta en un artículo que no es producible', function () {
    // Una receta en un artículo que no se produce es un costo que nadie usaría.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => $this->save->save($this->harina, [
        ['component_article_id' => $this->masa->id, 'quantity' => '1.0000', 'unit_id' => $this->g->id],
    ]))->toThrow(RecipeInvariantException::class);
});

it('RECHAZA un ingrediente que no está marcado como insumo (I5)', function () {
    // Es lo que hace explícita la doble modalidad de D16: un ingrediente es un insumo con costo
    // capturado, o un producible con receta propia — y en los dos casos está marcado como insumo.
    app(TenantContext::class)->set($this->tenant->id);

    $platillo = Article::factory()->sellable()->create(['name' => 'Sopa', 'is_supply' => false]);

    expect(fn () => $this->save->save($this->masa, [
        ['component_article_id' => $platillo->id, 'quantity' => '1.0000', 'unit_id' => $this->g->id],
    ]))->toThrow(RecipeInvariantException::class);
});

it('RECHAZA una línea cuya unidad es de otra magnitud que el insumo (I3)', function () {
    // La harina se mide en gramos; pedir 250 ml de harina exigiría conocer su densidad, que no es un dato
    // del sistema de unidades sino del artículo.
    app(TenantContext::class)->set($this->tenant->id);

    try {
        $this->save->save($this->masa, [
            ['component_article_id' => $this->harina->id, 'quantity' => '250.0000', 'unit_id' => $this->ml->id],
        ]);

        $this->fail('Debió rechazar la magnitud incompatible.');
    } catch (RecipeInvariantException $e) {
        expect($e->getMessage())->toContain('Harina');
        expect($e->getMessage())->toContain('presentación de compra');
    }
});

it('ACEPTA una línea en otra unidad de la MISMA magnitud', function () {
    // Kilogramos para un insumo que se mide en gramos: eso sí se convierte, y es como se captura una
    // receta de verdad.
    app(TenantContext::class)->set($this->tenant->id);

    $recipe = $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '1.5000', 'unit_id' => $this->kg->id],
    ]);

    expect($recipe->lines()->first()->unit_id)->toBe($this->kg->id);
});

it('RECHAZA que la receta rinda en una magnitud distinta a la del artículo', function () {
    // Sin magnitud común entre el rendimiento y la unidad base, el costo por unidad del artículo no se
    // puede calcular.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->ml->id))
        ->toThrow(RecipeInvariantException::class);
});

it('RECHAZA una receta sin ingredientes', function () {
    // Sin ninguno el costo sería cero y el sistema sugeriría venderlo gratis.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => $this->save->save($this->masa, []))
        ->toThrow(RecipeInvariantException::class);
});

it('el rendimiento por insumo se guarda y su divisor es correcto (D21)', function () {
    // 200 g de cebolla utilizable al 80 % son 250 g comprados: el rendimiento DIVIDE. Invertirlo
    // subvalúa sistemáticamente todos los costos del catálogo.
    app(TenantContext::class)->set($this->tenant->id);

    $cebolla = Article::factory()->create(['name' => 'Cebolla']);

    $recipe = $this->save->save($this->masa, [
        [
            'component_article_id' => $cebolla->id,
            'quantity' => '200.0000',
            'unit_id' => $this->g->id,
            'yield_percent' => '80.00',
        ],
    ]);

    $line = $recipe->lines()->first();

    expect(bccomp($line->yield_percent, '80', 2))->toBe(0);
    expect(bccomp($line->yieldDivisor(), '0.8', 8))->toBe(0);

    // Y la consecuencia: 200 ÷ 0.8 = 250. Es la cuenta que hará el motor de costeo del paso 6.
    expect(bccomp(bcdiv('200', $line->yieldDivisor(), 4), '250', 4))->toBe(0);
});

it('la base RECHAZA un rendimiento fuera de rango', function () {
    // Candado en la base y no sólo en validación: un 0 sería división por cero y más de 100 significaría
    // que del insumo sale más de lo que entró.
    app(TenantContext::class)->set($this->tenant->id);

    $recipe = Recipe::factory()->create(['article_id' => $this->masa->id]);

    expect(fn () => RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'yield_percent' => '0.00',
    ]))->toThrow(QueryException::class);

    expect(fn () => RecipeLine::factory()->create([
        'recipe_id' => $recipe->id,
        'yield_percent' => '120.00',
    ]))->toThrow(QueryException::class);
});

it('emite RecipeChanged al guardar y al eliminar', function () {
    // Dispara el recosteo en cascada del paso 6. `deleted` distingue "cambió la composición" de "ya no hay
    // receta": en el segundo caso el costo deja de ser calculable y hay que dejar de proyectarlo, no
    // recalcularlo a cero.
    Event::fake([RecipeChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    $recipe = $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ]);

    Event::assertDispatched(RecipeChanged::class, fn (RecipeChanged $e): bool => $e->deleted === false);

    $this->save->delete($recipe);

    Event::assertDispatched(RecipeChanged::class, fn (RecipeChanged $e): bool => $e->deleted === true);
});

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------

it('guarda y lee la receta por API', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->masa->ulid}/recipe", [
            'output_quantity' => '600.0000',
            'output_unit_ulid' => $this->g->ulid,
            'notes' => 'Amasar 10 minutos',
            'lines' => [
                [
                    'component_ulid' => $this->harina->ulid,
                    'quantity' => '500.0000',
                    'unit_ulid' => $this->g->ulid,
                    'yield_percent' => '95.00',
                ],
            ],
        ])
        ->assertOk();

    expect($respuesta->json('data.lines.0.component.name'))->toBe('Harina');
    expect($respuesta->json('data.lines.0.yield_percent'))->toBe('95.00');
    expect($respuesta->json('data.output_unit.code'))->toBe('g');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->masa->ulid}/recipe")
        ->assertOk()
        ->assertJsonPath('data.notes', 'Amasar 10 minutos');
});

it('un artículo sin receta devuelve 404 y no una receta vacía', function () {
    // "No tiene receta" y "tiene una receta sin ingredientes" son estados distintos, y el segundo no
    // existe.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->masa->ulid}/recipe")
        ->assertStatus(404);
});

it('un ciclo por API devuelve 422 con el camino, no 500', function () {
    // El usuario capturó algo que el negocio no admite y necesita ver el camino para arreglarlo. Un 500
    // diría "el sistema falló" y esconde justo la información que resuelve el problema.
    app(TenantContext::class)->set($this->tenant->id);
    cadenaDeTresNiveles();
    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->masa->ulid}/recipe", [
            'output_quantity' => '1.0000',
            'lines' => [
                ['component_ulid' => $this->torta->ulid, 'quantity' => '1.0000', 'unit_ulid' => $this->g->ulid],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');

    expect($respuesta->json('errors.lines.0'))->toContain('Torta');
});

it('RECHAZA por API un ingrediente repetido con un mensaje que lo nombra', function () {
    // Hay un índice único que lo impide, pero llegar hasta él daría un error de base de datos en lugar de
    // decir qué línea repetir.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->masa->ulid}/recipe", [
            'output_quantity' => '1.0000',
            'lines' => [
                ['component_ulid' => $this->harina->ulid, 'quantity' => '1.0000', 'unit_ulid' => $this->g->ulid],
                ['component_ulid' => $this->harina->ulid, 'quantity' => '2.0000', 'unit_ulid' => $this->g->ulid],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');

    expect($respuesta->json('errors.lines.0'))->toContain('Harina');
});

it('elimina la receta sin quitarle la capacidad de producible al artículo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/articles/{$this->masa->ulid}/recipe")
        ->assertStatus(204);

    app(TenantContext::class)->set($this->tenant->id);

    expect(Recipe::query()->where('article_id', $this->masa->id)->exists())->toBeFalse();

    // La capacidad es una decisión de catálogo, con su propio permiso.
    expect($this->masa->fresh()->is_producible)->toBeTrue();
});

it('un ingrediente de OTRO negocio no existe para la validación', function () {
    // El aislamiento no depende de que el cliente mande identificadores válidos: el ULID ajeno
    // simplemente no se resuelve, y el mensaje es el mismo que para uno inventado.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    $ajeno = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn (): Article => Article::factory()->create(['name' => 'Insumo ajeno'])
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->masa->ulid}/recipe", [
            'output_quantity' => '1.0000',
            'lines' => [
                ['component_ulid' => $ajeno->ulid, 'quantity' => '1.0000', 'unit_ulid' => $this->g->ulid],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.component_ulid');
});

it('un MESERO no ve ni edita recetas; un ALMACENISTA las ve y no las edita', function () {
    $roles = app(TenantContext::class)->runFor($this->tenant->id, fn (): array => [
        'mesero' => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail(),
        'almacenista' => Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail(),
    ]);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($roles): void {
        $this->owner->assignRole($roles['mesero']);
        $this->owner->assignRole($roles['almacenista']);

        $this->save->save($this->masa, [
            ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
        ]);
    });

    // Un mesero no tiene nada que hacer con la composición de un platillo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $roles['mesero']->ulid)
        ->getJson("/api/v1/articles/{$this->masa->ulid}/recipe")
        ->assertStatus(403);

    // El almacenista sí la consulta —necesita saber qué consume cada producción— y no la edita.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $roles['almacenista']->ulid)
        ->getJson("/api/v1/articles/{$this->masa->ulid}/recipe")
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $roles['almacenista']->ulid)
        ->putJson("/api/v1/articles/{$this->masa->ulid}/recipe", [
            'output_quantity' => '1.0000',
            'lines' => [
                ['component_ulid' => $this->harina->ulid, 'quantity' => '1.0000', 'unit_ulid' => $this->g->ulid],
            ],
        ])
        ->assertStatus(403);
});
