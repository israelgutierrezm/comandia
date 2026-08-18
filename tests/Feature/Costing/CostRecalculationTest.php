<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\RecipeCycleDetector;
use App\Modules\Costing\Application\RecostArticle;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Costing\Jobs\RecalculateDependentCosts;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Queue;

/**
 * RECÁLCULO TRANSITIVO DE COSTOS (D16)
 *
 * Cambiar el costo de la harina tiene que llegar al pan y de ahí a la torta, sin que quien captura el costo
 * sepa que existe una cascada. Es el primer job del proyecto, así que aquí también se verifican las dos
 * cosas que un job tiene que cumplir en este sistema: llevar su tenant y ser idempotente.
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
    $this->pza = Unit::query()->where('code', 'pza')->firstOrFail();

    $this->save = app(SaveRecipe::class);
    $this->capture = app(CaptureArticleCost::class);

    // Harina → Masa → Pan → Torta, con los mismos números de la suite de cascada.
    $this->harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $this->masa = Article::factory()->producible()->create(['name' => 'Masa', 'base_unit_id' => $this->g->id]);
    $this->pan = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $this->g->id]);
    $this->torta = Article::factory()->producible()->create(['name' => 'Torta', 'base_unit_id' => $this->pza->id]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Arma la cadena de recetas. Se llama dentro de contexto. */
function armarCadena(): void
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
    ], outputQuantity: '1.0000', outputUnitId: $t->pza->id);
}

it('capturar un costo encola el recálculo de los dependientes', function () {
    Queue::fake();

    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();

    $this->capture->atUnitCost($this->harina, '0.0200');

    Queue::assertPushed(
        RecalculateDependentCosts::class,
        fn (RecalculateDependentCosts $job): bool => $job->queue === 'default'
    );
});

it('una captura RETROACTIVA no encola nada', function () {
    // Recostear por una captura que no es la vigente sería trabajo inútil con resultado equivocado: el motor
    // usaría el costo actual, no el retroactivo, y escribiría un recálculo idéntico al que ya existe.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();

    $this->capture->atUnitCost($this->harina, '0.0200');

    Queue::fake();

    $this->capture->atUnitCost($this->harina, '0.0100', effectiveAt: now()->subDays(7)->toImmutable());

    Queue::assertNothingPushed();
});

it('el recálculo llega hasta TRES niveles arriba', function () {
    // La razón de ser del módulo: el costo de la harina llega a la torta sin que nadie lo pida.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();

    $this->capture->atUnitCost($this->harina, '0.0200');

    // La cadena da 0.01666667 / g de masa, 0.02 / g de pan y 2.40 por torta.
    $costo = fn (Article $a): ?string => ArticleCurrentCost::query()
        ->where('article_id', $a->id)
        ->value('unit_cost');

    expect(bccomp($costo($this->masa), '0.0167', 4))->toBe(0);
    expect(bccomp($costo($this->pan), '0.02', 4))->toBe(0);
    expect(bccomp($costo($this->torta), '2.4', 4))->toBe(0);
});

it('el historial del dependiente enlaza con la variación que lo causó', function () {
    // «La torta subió porque subió la harina», con enlace. Es lo que convierte el historial en algo
    // investigable en lugar de una lista de números con fechas.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();

    $origen = $this->capture->atUnitCost($this->harina, '0.0200');

    $enCascada = ArticleCost::query()
        ->where('article_id', $this->torta->id)
        ->where('origin', CostOrigin::RecipeCascade->value)
        ->firstOrFail();

    expect($enCascada->source_cost_id)->toBe($origen->id);
    expect($enCascada->actor_membership_id)->toBeNull();
});

it('guardar una receta recostea al dueño EN EL MOMENTO', function () {
    // Quien acaba de guardar una receta está mirando la pantalla. Dejarlo a la cola le mostraría el costo
    // viejo unos segundos, y la conclusión natural sería que el sistema no guardó su cambio.
    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->harina, '0.0200');

    Queue::fake();

    $this->save->save($this->masa, [
        ['component_article_id' => $this->harina->id, 'quantity' => '500.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '600.0000', outputUnitId: $this->g->id);

    // Sin procesar la cola: la masa ya tiene su costo.
    expect(bccomp(
        ArticleCurrentCost::query()->where('article_id', $this->masa->id)->value('unit_cost'),
        '0.0167',
        4
    ))->toBe(0);

    // Y los dependientes quedaron encolados.
    Queue::assertPushed(RecalculateDependentCosts::class);
});

it('cambiar la receta cambia el costo de todo lo que está arriba', function () {
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    $this->capture->atUnitCost($this->harina, '0.0200');

    $antes = ArticleCurrentCost::query()->where('article_id', $this->torta->id)->value('unit_cost');

    // El doble de pan por torta.
    $this->save->save($this->torta, [
        ['component_article_id' => $this->pan->id, 'quantity' => '240.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    $despues = ArticleCurrentCost::query()->where('article_id', $this->torta->id)->value('unit_cost');

    expect(bccomp($antes, '2.4', 4))->toBe(0);
    expect(bccomp($despues, '4.8', 4))->toBe(0);
});

it('el job es IDEMPOTENTE: procesarlo dos veces no duplica historial', function () {
    // Requisito de CLAUDE.md. El índice único lo hace infalsificable, y el servicio traga la colisión porque
    // un re-despacho no es un fallo.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();

    $origen = $this->capture->atUnitCost($this->harina, '0.0200');

    $filas = fn (): int => ArticleCost::query()
        ->where('origin', CostOrigin::RecipeCascade->value)
        ->count();

    $antes = $filas();

    expect($antes)->toBeGreaterThan(0);

    // El mismo job otra vez, tal como lo re-despacharía la cola tras un fallo de red.
    app(RecalculateDependentCosts::class, [
        'tenantId' => $this->tenant->id,
        'articleId' => $this->harina->id,
        'sourceCostId' => $origen->id,
    ])->handle(
        app(TenantContext::class),
        app(RecipeCycleDetector::class),
        app(RecostArticle::class),
    );

    expect($filas())->toBe($antes);
});

it('el job abre el contexto de tenant por su cuenta', function () {
    // En el worker NO hay contexto: el job lo lleva explícito y lo abre con `runFor()`. Es la segunda fuente
    // legítima de `tenant_id` —un job es la continuación de una petición que ya lo resolvió— y sin esto el
    // scope global lanzaría excepción.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    $origen = $this->capture->atUnitCost($this->harina, '0.0200');

    // Se limpia el contexto, como en un worker recién arrancado.
    app(TenantContext::class)->forget();

    expect(app(TenantContext::class)->has())->toBeFalse();

    app(RecalculateDependentCosts::class, [
        'tenantId' => $this->tenant->id,
        'articleId' => $this->harina->id,
        'sourceCostId' => $origen->id,
    ])->handle(
        app(TenantContext::class),
        app(RecipeCycleDetector::class),
        app(RecostArticle::class),
    );

    // Y lo deja como estaba: `runFor` restaura en `finally`.
    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('el job SOBREVIVE a serializarse y correr sin contexto', function () {
    // Es la prueba de la decisión de llevar IDENTIFICADORES y no modelos.
    //
    // Con `SerializesModels`, deserializar volvería a consultar el modelo, y en el worker no hay contexto de
    // tenant: el global scope lanzaría excepción antes de que el job pudiera abrirlo. Es una trampa con
    // forma de comodidad, y la única manera de comprobar que no se cayó en ella es serializar de verdad.
    //
    // La suite corre con `QUEUE_CONNECTION=sync`, así que sin esta prueba el ciclo de vida real del job
    // —serializar, deserializar sin contexto, ejecutar— no se ejercitaría nunca.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    $origen = $this->capture->atUnitCost($this->harina, '0.0200');

    $job = new RecalculateDependentCosts($this->tenant->id, $this->harina->id, $origen->id);

    // Ida y vuelta por el mismo mecanismo que usa la cola.
    $revivido = unserialize(serialize($job));

    expect($revivido)->toBeInstanceOf(RecalculateDependentCosts::class);

    // Se borra el costo calculado para poder ver que el job revivido lo vuelve a producir.
    app(TenantContext::class)->forget();

    expect(app(TenantContext::class)->has())->toBeFalse();

    $revivido->handle(
        app(TenantContext::class),
        app(RecipeCycleDetector::class),
        app(RecostArticle::class),
    );

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(bccomp(
            ArticleCurrentCost::query()->where('article_id', $this->torta->id)->value('unit_cost'),
            '2.4',
            4
        ))->toBe(0);
    });
});

it('el job de un negocio NO toca los artículos de otro', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    // Un artículo producible con el mismo nombre en el otro negocio, sin costo.
    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        $g = Unit::query()->where('code', 'g')->firstOrFail();
        $harinaAjena = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $g->id]);
        $panAjeno = Article::factory()->producible()->create(['name' => 'Pan', 'base_unit_id' => $g->id]);

        app(SaveRecipe::class)->save($panAjeno, [
            ['component_article_id' => $harinaAjena->id, 'quantity' => '100.0000', 'unit_id' => $g->id],
        ], outputQuantity: '100.0000', outputUnitId: $g->id);
    });

    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    $this->capture->atUnitCost($this->harina, '0.0200');
    app(TenantContext::class)->forget();

    // El otro negocio sigue sin ningún costo proyectado.
    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        expect(ArticleCurrentCost::query()->count())->toBe(0);
        expect(ArticleCost::query()->count())->toBe(0);
    });
});

it('eliminar la receta NO recostea al dueño, y su último costo conocido se queda', function () {
    // La proyección espeja la última fila del historial inmutable (P4), y borrar una receta no borra
    // historia. Quien pregunte por el desglose recibirá "no calculable", que es la respuesta honesta.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    $this->capture->atUnitCost($this->harina, '0.0200');

    $recetaMasa = Recipe::query()
        ->where('article_id', $this->masa->id)
        ->firstOrFail();

    app(SaveRecipe::class)->delete($recetaMasa);

    // El costo conocido de la masa se conserva.
    expect(bccomp(
        ArticleCurrentCost::query()->where('article_id', $this->masa->id)->value('unit_cost'),
        '0.0167',
        4
    ))->toBe(0);

    // Y NO se escribió una fila de cascada nueva para ella al quedarse sin receta: eso mentiría en `origin`.
    $ultima = ArticleCost::query()
        ->where('article_id', $this->masa->id)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($ultima->origin)->toBe(CostOrigin::RecipeCascade);
    expect(bccomp($ultima->unit_cost, '0.0167', 4))->toBe(0);
});

// ---------------------------------------------------------------------------
// Impacto
// ---------------------------------------------------------------------------

it('el impacto lista los dependientes y distingue directo de indirecto', function () {
    // Se consulta ANTES de capturar un costo: subir el jitomate cambia el costo de catorce platillos y quien
    // lo captura tiene derecho a saberlo antes de guardar.
    app(TenantContext::class)->set($this->tenant->id);
    armarCadena();
    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->harina->ulid}/impact")
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(3);

    $porNombre = collect($data['dependents'])->keyBy('name');

    // La masa usa la harina directamente; el pan y la torta la alcanzan a través de sub-recetas.
    expect($porNombre['Masa']['is_direct'])->toBeTrue();
    expect($porNombre['Pan']['is_direct'])->toBeFalse();
    expect($porNombre['Torta']['is_direct'])->toBeFalse();
});

it('un artículo que nadie usa reporta impacto vacío', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $suelto = Article::factory()->create(['name' => 'Servilletas', 'base_unit_id' => $this->pza->id]);
    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$suelto->ulid}/impact")
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(0);
    expect($data['dependents'])->toBe([]);
});

it('un MESERO no consulta el impacto', function () {
    // Revela la composición de los platillos, que es información sensible del negocio.
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->owner->assignRole($mesero));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson("/api/v1/articles/{$this->harina->ulid}/impact")
        ->assertStatus(403);
});
