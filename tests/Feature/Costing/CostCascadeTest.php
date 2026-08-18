<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CalculateArticleCost;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\RecostArticle;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Domain\Exceptions\CostCycleDetectedException;
use App\Modules\Costing\Events\ArticleCostChanged;
use App\Modules\Costing\Events\RecipeChanged;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Costing\Infrastructure\Models\RecipeLine;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Event;

/**
 * MOTOR DE COSTEO EN CASCADA (D16, D21)
 *
 * Las aserciones son sobre **números exactos** y no aproximaciones: el propósito de haber desviado la
 * convención de dinero a cuatro decimales (P3) y de usar `bcmath` en lugar de `float` es que el resultado
 * sea reproducible al último decimal. Una prueba con `toBeCloseTo` no verificaría nada de eso.
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
    $this->pza = Unit::query()->where('code', 'pza')->firstOrFail();

    $this->calc = app(CalculateArticleCost::class);
    $this->save = app(SaveRecipe::class);
    $this->capture = app(CaptureArticleCost::class);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * La cadena de tres niveles que se usa en varias pruebas:
 *
 *   Harina  costo 0.0200 / g   ($20 el kilo)
 *   Masa    500 g de harina, rinde 600 g   → 10.00 / 600 = 0.01666667 / g
 *   Pan     300 g de masa,   rinde 250 g   → 5.000001 / 250 = 0.02000000 / g
 *   Torta   120 g de pan,    rinde 1 pza   → 2.40000000 / pza
 */
function cadena(): array
{
    $t = test();

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $t->g->id]);
    $masa = Article::factory()->producible()->create(['name' => 'Masa', 'base_unit_id' => $t->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $t->g->id]);
    $torta = Article::factory()->producible()->create(['name' => 'Torta', 'base_unit_id' => $t->pza->id]);

    $t->capture->atUnitCost($harina, '0.0200');

    $t->save->save($masa, [
        ['component_article_id' => $harina->id, 'quantity' => '500.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $t->g->id);

    $t->save->save($pan, [
        ['component_article_id' => $masa->id, 'quantity' => '300.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '250.0000', outputUnitId: $t->g->id);

    $t->save->save($torta, [
        ['component_article_id' => $pan->id, 'quantity' => '120.0000', 'unit_id' => $t->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $t->pza->id);

    return compact('harina', 'masa', 'pan', 'torta');
}

it('costea una receta plana', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $galleta = Article::factory()->producible()->create(['name' => 'Galleta', 'base_unit_id' => $this->pza->id]);

    $this->capture->atUnitCost($harina, '0.0200');

    // 50 g de harina a 0.02 = 1.00, y rinde 4 galletas → 0.25 por galleta.
    $this->save->save($galleta, [
        ['component_article_id' => $harina->id, 'quantity' => '50.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '4.0000', outputUnitId: $this->pza->id);

    expect(bccomp($this->calc->unitCost($galleta), '0.25', 8))->toBe(0);
});

it('convierte la unidad de la línea a la unidad base del insumo', function () {
    // La receta se captura como se cocina —"1.5 kg de harina"— y el costo está por gramo.
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $tanda = Article::factory()->producible()->create(['name' => 'Tanda', 'base_unit_id' => $this->pza->id]);

    $this->capture->atUnitCost($harina, '0.0200');

    // 1.5 kg = 1500 g × 0.02 = 30.00, rinde 1 → 30.0000.
    $this->save->save($tanda, [
        ['component_article_id' => $harina->id, 'quantity' => '1.5000', 'unit_id' => $this->kg->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    expect(bccomp($this->calc->unitCost($tanda), '30', 8))->toBe(0);
});

it('el rendimiento DIVIDE: 200 g al 80 % cuestan como 250 g', function () {
    // El error de dirección más costoso del módulo: invertirlo subvalúa sistemáticamente todos los costos
    // del catálogo, siempre en el mismo sentido, y el margen reportado sale optimista sin que nada falle.
    app(TenantContext::class)->set($this->tenant->id);

    $cebolla = Article::factory()->create(['name' => 'Cebolla', 'base_unit_id' => $this->g->id]);
    $sofrito = Article::factory()->producible()->create(['name' => 'Sofrito', 'base_unit_id' => $this->pza->id]);

    $this->capture->atUnitCost($cebolla, '0.0500');

    // 200 g × 0.05 = 10.00, ÷ 0.8 = 12.50. Multiplicar en lugar de dividir daría 8.00.
    $this->save->save($sofrito, [
        [
            'component_article_id' => $cebolla->id,
            'quantity' => '200.0000',
            'unit_id' => $this->g->id,
            'yield_percent' => '80.00',
        ],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    $cost = $this->calc->unitCost($sofrito);

    expect(bccomp($cost, '12.5', 8))->toBe(0);
    expect(bccomp($cost, '8', 8))->not->toBe(0);
});

it('costea TRES niveles de cascada al último decimal', function () {
    // Harina → Masa → Pan → Torta. Es el caso que justifica todo el módulo: cambiar el costo de la harina
    // tiene que llegar hasta la torta, y el número tiene que ser reproducible.
    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();

    expect(bccomp($this->calc->unitCost($a['masa']), '0.01666667', 8))->toBe(0);
    expect(bccomp($this->calc->unitCost($a['pan']), '0.02', 8))->toBe(0);
    expect(bccomp($this->calc->unitCost($a['torta']), '2.4', 8))->toBe(0);
});

it('el desglose trae la cascada abierta y los pasos del cálculo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();

    $breakdown = $this->calc->breakdown($a['torta']);

    expect($breakdown->isComputable())->toBeTrue();
    expect($breakdown->lines)->toHaveCount(1);

    $linea = $breakdown->lines[0];

    expect($linea->componentName)->toBe('Pan');
    expect($linea->componentIsProducible)->toBeTrue();

    // La conversión, a la vista: 120 g y el insumo se mide en g.
    expect(bccomp($linea->quantityInComponentBaseUnit, '120', 8))->toBe(0);
    expect($linea->componentBaseUnitCode)->toBe('g');

    // Y el desglose del pan anidado, que a su vez trae el de la masa.
    expect($linea->subLines)->toHaveCount(1);
    expect($linea->subLines[0]->componentName)->toBe('Masa');
    expect($linea->subLines[0]->subLines[0]->componentName)->toBe('Harina');
});

it('un insumo SIN costo hace que el resultado NO sea calculable, y los nombra', function () {
    // La regla que evita el peor resultado posible: sumar los costos conocidos daría un número más bajo
    // que el real presentado como completo, y de ahí un precio sugerido y un margen equivocados.
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $sal = Article::factory()->create(['name' => 'Sal', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);

    // Sólo la harina tiene costo.
    $this->capture->atUnitCost($harina, '0.0200');

    $this->save->save($pan, [
        ['component_article_id' => $harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
        ['component_article_id' => $sal->id, 'quantity' => '10.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $this->g->id);

    $breakdown = $this->calc->breakdown($pan);

    expect($breakdown->unitCost)->toBeNull();
    expect($breakdown->isComputable())->toBeFalse();
    expect($breakdown->missingCosts)->toBe(['Sal']);

    // La línea de la harina sí trae su costo: el desglose muestra lo que se sabe y lo que falta.
    expect($breakdown->lines[0]->lineCost)->not->toBeNull();
    expect($breakdown->lines[1]->lineCost)->toBeNull();
});

it('un insumo sin costo a TRES niveles de profundidad también invalida el resultado', function () {
    // El caso que un cálculo que sólo mirara el primer nivel daría por bueno.
    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();

    // Se agrega a la masa un insumo sin costo. La torta está dos niveles arriba.
    $levadura = Article::factory()->create(['name' => 'Levadura', 'base_unit_id' => $this->g->id]);

    $this->save->save($a['masa'], [
        ['component_article_id' => $a['harina']->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
        ['component_article_id' => $levadura->id, 'quantity' => '5.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $this->g->id);

    $breakdown = $this->calc->breakdown($a['torta']);

    expect($breakdown->isComputable())->toBeFalse();
    expect($breakdown->missingCosts)->toContain('Levadura');
});

it('un grafo en DIAMANTE costea bien y la sub-receta se calcula una sola vez', function () {
    // El pan y la salsa usan los dos la misma masa. La memoización evita costear la masa dos veces; lo que
    // se comprueba aquí es que el resultado es correcto, que es la parte observable.
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $masa = Article::factory()->producible()->create(['name' => 'Masa', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);
    $empanada = Article::factory()->producible()->create(['name' => 'Empanada', 'base_unit_id' => $this->pza->id]);
    $combo = Article::factory()->producible()->create(['name' => 'Combo', 'base_unit_id' => $this->pza->id]);

    $this->capture->atUnitCost($harina, '0.0100');

    // masa: 1000 g harina a 0.01 = 10.00, rinde 1000 g → 0.01 / g
    $this->save->save($masa, [
        ['component_article_id' => $harina->id, 'quantity' => '1000.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1000.0000', outputUnitId: $this->g->id);

    // pan: 100 g masa = 1.00, rinde 100 g → 0.01 / g
    $this->save->save($pan, [
        ['component_article_id' => $masa->id, 'quantity' => '100.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '100.0000', outputUnitId: $this->g->id);

    // empanada: 50 g masa = 0.50, rinde 1 pza → 0.50 / pza
    $this->save->save($empanada, [
        ['component_article_id' => $masa->id, 'quantity' => '50.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    // combo: 200 g de pan (2.00) + 1 empanada (0.50) = 2.50, rinde 1 pza
    $this->save->save($combo, [
        ['component_article_id' => $pan->id, 'quantity' => '200.0000', 'unit_id' => $this->g->id],
        ['component_article_id' => $empanada->id, 'quantity' => '1.0000', 'unit_id' => $this->pza->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    expect(bccomp($this->calc->unitCost($combo), '2.5', 8))->toBe(0);
});

it('un ciclo escrito a mano hace que el motor FALLE en lugar de recurrir sin fin', function () {
    // Guardar un ciclo es imposible, así que esto sólo puede pasar con datos que llegaron por otro camino
    // —SQL a mano, una importación—. La guardia existe porque la alternativa es un proceso que no termina.
    app(TenantContext::class)->set($this->tenant->id);

    $masa = Article::factory()->producible()->create(['name' => 'Masa', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);

    $recetaMasa = Recipe::factory()->create(['article_id' => $masa->id, 'output_unit_id' => $this->g->id]);
    RecipeLine::factory()->create([
        'recipe_id' => $recetaMasa->id,
        'component_article_id' => $pan->id,
        'unit_id' => $this->g->id,
    ]);

    $recetaPan = Recipe::factory()->create(['article_id' => $pan->id, 'output_unit_id' => $this->g->id]);
    RecipeLine::factory()->create([
        'recipe_id' => $recetaPan->id,
        'component_article_id' => $masa->id,
        'unit_id' => $this->g->id,
    ]);

    expect(fn () => $this->calc->breakdown($masa))->toThrow(CostCycleDetectedException::class);
});

// ---------------------------------------------------------------------------
// Persistencia del costo calculado (P5, aplicada según recomendación)
// ---------------------------------------------------------------------------

it('registra el costo calculado con origen recipe_cascade y actualiza la proyección', function () {
    // Se silencian los eventos para probar `RecostArticle` EN AISLAMIENTO.
    //
    // Desde el paso 7, guardar una receta y capturar un costo disparan la cascada automática, que ya deja
    // todo costeado. Sin silenciarla, el recosteo manual de esta prueba no tendría nada que hacer y
    // devolvería null — no por un defecto, sino porque el trabajo ya estaba hecho.
    Event::fake([ArticleCostChanged::class, RecipeChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();

    $cost = app(RecostArticle::class)->recost($a['torta']);

    expect($cost)->not->toBeNull();
    expect($cost->origin)->toBe(CostOrigin::RecipeCascade);
    expect($cost->actor_membership_id)->toBeNull();
    expect(bccomp($cost->unit_cost, '2.4', 4))->toBe(0);

    $projection = ArticleCurrentCost::query()->where('article_id', $a['torta']->id)->firstOrFail();

    expect(bccomp($projection->unit_cost, '2.4', 4))->toBe(0);
    expect($projection->source_cost_id)->toBe($cost->id);
});

it('NO escribe una fila nueva si el costo no cambió', function () {
    // Un historial con una fila por cada recálculo que dio el mismo número es un historial que nadie puede
    // leer. Y el recálculo se dispara en cascada, así que un cambio en la sal generaría una fila en cada
    // artículo que la use.
    // Aislado de la cascada automática del paso 7, que ya habría hecho el trabajo.
    Event::fake([ArticleCostChanged::class, RecipeChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();
    $recost = app(RecostArticle::class);

    expect($recost->recost($a['torta']))->not->toBeNull();
    expect($recost->recost($a['torta']))->toBeNull();
    expect($recost->recost($a['torta']))->toBeNull();

    expect(ArticleCost::query()
        ->where('article_id', $a['torta']->id)
        ->where('origin', CostOrigin::RecipeCascade->value)
        ->count())->toBe(1);
});

it('NO escribe nada si el costo no es calculable', function () {
    // No se escribe un cero —diría que producirlo es gratis— y tampoco se borra la proyección anterior: el
    // último costo conocido sigue siendo la mejor información disponible.
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);

    $this->save->save($pan, [
        ['component_article_id' => $sal->id, 'quantity' => '10.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->g->id);

    expect(app(RecostArticle::class)->recost($pan))->toBeNull();
    expect(ArticleCurrentCost::query()->where('article_id', $pan->id)->exists())->toBeFalse();
});

it('el recálculo es IDEMPOTENTE por llave, aunque el costo haya cambiado', function () {
    // Requisito de CLAUDE.md: re-despachar un job no puede duplicar un movimiento. El índice único lo hace
    // imposible y el servicio traga la colisión, porque un re-despacho no es un fallo.
    //
    // Aislado de la cascada automática del paso 7: aquí se prueba el servicio, no el enganche por evento.
    Event::fake([ArticleCostChanged::class, RecipeChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();
    $recost = app(RecostArticle::class);

    $primero = $recost->recost($a['torta'], idempotencyKey: 'recost:torta:1');

    expect($primero)->not->toBeNull();

    // Cambia el costo de la harina, así que el costo de la torta cambia de verdad...
    $this->capture->atUnitCost($a['harina'], '0.0400');

    // ...pero la misma llave no vuelve a escribir.
    expect($recost->recost($a['torta'], idempotencyKey: 'recost:torta:1'))->toBeNull();

    expect(ArticleCost::query()
        ->where('article_id', $a['torta']->id)
        ->where('origin', CostOrigin::RecipeCascade->value)
        ->count())->toBe(1);
});

it('el promedio del periodo IGNORA el costo calculado (D14)', function () {
    // La condición que P5 obliga a respetar al meter los dos orígenes en la misma tabla: promediar un costo
    // calculado con costos de compra mezcla dos magnitudes y da un número sin significado.
    app(TenantContext::class)->set($this->tenant->id);

    $a = cadena();
    app(RecostArticle::class)->recost($a['torta']);

    // Y una adquisición para la torta, como si se hubiera comprado hecha.
    $this->capture->atUnitCost($a['torta'], '10.0000');

    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$a['torta']->ulid}/cost")
        ->assertOk()
        ->json('data');

    // Sólo la adquisición de 10: si contara el 2.4 calculado, el promedio sería 6.2.
    expect((float) $data['period_average'])->toBe(10.0);
});

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------

it('el desglose por API trae el costo, la tanda y la cascada anidada', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $a = cadena();
    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$a['torta']->ulid}/cost-breakdown")
        ->assertOk()
        ->json('data');

    expect($data['is_computable'])->toBeTrue();
    expect(bccomp($data['unit_cost'], '2.4', 4))->toBe(0);
    expect($data['missing_costs'])->toBe([]);

    // La tanda y lo que rinde: los dos hacen falta para entender de dónde sale el costo unitario.
    expect(bccomp($data['batch_cost'], '2.4', 4))->toBe(0);
    expect(bccomp($data['batch_yield_in_base_unit'], '1', 4))->toBe(0);

    expect($data['lines'][0]['component_name'])->toBe('Pan');
    expect($data['lines'][0]['is_producible'])->toBeTrue();
    expect($data['lines'][0]['sub_lines'][0]['component_name'])->toBe('Masa');
    expect($data['lines'][0]['sub_lines'][0]['sub_lines'][0]['component_name'])->toBe('Harina');
});

it('el desglose por API dice qué falta cuando no es calculable', function () {
    // Es lo accionable de la respuesta: sin la lista, un "no calculable" deja al usuario buscando a mano
    // cuál de treinta insumos no tiene costo.
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);

    $this->save->save($pan, [
        ['component_article_id' => $sal->id, 'quantity' => '10.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->g->id);

    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$pan->ulid}/cost-breakdown")
        ->assertOk()
        ->json('data');

    expect($data['is_computable'])->toBeFalse();
    expect($data['unit_cost'])->toBeNull();
    expect($data['missing_costs'])->toBe(['Sal']);
});

it('un artículo sin receta devuelve su costo capturado como desglose de una pieza', function () {
    // Así el cliente no tiene que distinguir "producible" de "insumo" para pedir un costo.
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $this->capture->atUnitCost($harina, '0.0200');

    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$harina->ulid}/cost-breakdown")
        ->assertOk()
        ->json('data');

    expect($data['is_computable'])->toBeTrue();
    expect(bccomp($data['unit_cost'], '0.02', 4))->toBe(0);
    expect($data['lines'])->toBe([]);
});

it('un ciclo al calcular devuelve 409 y no 422 ni 500', function () {
    // No hay nada en el cuerpo enviado que el usuario pueda corregir: es dato corrupto. Un 422 le haría
    // buscar el error donde no está, y un 500 esconde el diagnóstico.
    app(TenantContext::class)->set($this->tenant->id);

    $masa = Article::factory()->producible()->create(['name' => 'Masa', 'base_unit_id' => $this->g->id]);
    $pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);

    foreach ([[$masa, $pan], [$pan, $masa]] as [$dueno, $componente]) {
        $receta = Recipe::factory()->create([
            'article_id' => $dueno->id,
            'output_unit_id' => $this->g->id,
        ]);

        RecipeLine::factory()->create([
            'recipe_id' => $receta->id,
            'component_article_id' => $componente->id,
            'unit_id' => $this->g->id,
        ]);
    }

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$masa->ulid}/cost-breakdown")
        ->assertStatus(409)
        ->assertJsonPath('type', 'conflict');
});

it('un MESERO no ve el desglose de costos', function () {
    // El desglose es la información más sensible del catálogo: dice cuánto se gana con cada platillo.
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $articulo = app(TenantContext::class)->runFor($this->tenant->id, function () use ($mesero): Article {
        $this->owner->assignRole($mesero);

        return Article::factory()->create(['base_unit_id' => $this->g->id]);
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson("/api/v1/articles/{$articulo->ulid}/cost-breakdown")
        ->assertStatus(403);
});
