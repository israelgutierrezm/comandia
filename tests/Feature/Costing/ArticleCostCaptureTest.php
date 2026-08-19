<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\RebuildCurrentCosts;
use App\Modules\Costing\Domain\Enums\CostOrigin;
use App\Modules\Costing\Events\ArticleCostChanged;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;

/**
 * CAPTURA DE COSTO E HISTORIAL (D14, P3, P4)
 *
 * Lo que se prueba aquí no es un CRUD: es que el costo vigente y su historial no puedan divergir, que
 * una captura retroactiva no pise el costo actual, y que la precisión de cuatro decimales sirva para lo
 * que se aprobó — costear insumos que valen millonésimas por unidad.
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
    $this->membership = $alta['membership'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->article = Article::factory()->create(['name' => 'Jitomate']);
    $this->capture = app(CaptureArticleCost::class);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('registra un costo y deja la proyección al día', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->article, '24.5000');

    $projection = ArticleCurrentCost::query()->where('article_id', $this->article->id)->firstOrFail();

    expect(bccomp($projection->unit_cost, '24.5', 4))->toBe(0);
    expect($projection->source_cost_id)->not->toBeNull();

    // Y el historial tiene su fila: la proyección es caché, el historial es la verdad.
    expect(ArticleCost::query()->where('article_id', $this->article->id)->count())->toBe(1);
});

it('la precisión de cuatro decimales sirve para lo que se aprobó en P3', function () {
    // El caso que justificó desviarse de "dinero = DECIMAL(12,2)": el gramo de sal. A dos decimales
    // sería cero, y toda receta que use sal costaría cero.
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal']);

    $this->capture->atUnitCost($sal, '0.0035');

    $projection = ArticleCurrentCost::query()->where('article_id', $sal->id)->firstOrFail();

    expect(bccomp($projection->unit_cost, '0.0035', 4))->toBe(0);
    expect(bccomp($projection->unit_cost, '0', 4))->not->toBe(0);
});

it('captura por presentación de compra: divide el total entre lo que rinde', function () {
    // "Compré un costal de 25 kg en $600" es como piensa el dueño; $24 por kilo es lo que el sistema
    // necesita. Pedirle que divida a mano es pedirle el cálculo donde se equivoca (D22).
    app(TenantContext::class)->set($this->tenant->id);

    $presentation = ArticlePurchasePresentation::factory()
        ->yielding('25.0000', 'Costal de 25 kg')
        ->create(['article_id' => $this->article->id]);

    $cost = $this->capture->fromPresentation($presentation, '600.00');

    expect(bccomp($cost->unit_cost, '24', 4))->toBe(0);
});

it('la división por presentación redondea media-arriba y no trunca', function () {
    // `bcmath` trunca, y truncar sistemáticamente sesga TODOS los costos hacia abajo: cada insumo de
    // cada receta pierde una fracción, siempre en el mismo sentido, y el margen que el sistema reporta
    // acaba siendo optimista.
    app(TenantContext::class)->set($this->tenant->id);

    // Caso donde redondear y truncar COINCIDEN: 100 / 3 = 33.3333|33…, el quinto decimal es 3, así
    // que las dos estrategias dan 33.3333. Sirve de control: si esta falla, el problema es la
    // división, no el redondeo.
    $tercios = ArticlePurchasePresentation::factory()
        ->yielding('3.0000')
        ->create(['article_id' => $this->article->id]);

    expect(bccomp($this->capture->fromPresentation($tercios, '100.00')->unit_cost, '33.3333', 4))->toBe(0);

    // Caso donde DIFIEREN, que es el que prueba de verdad: 200 / 3 = 66.6666|66…, el quinto decimal
    // es 6, así que redondear da 66.6667 y truncar daría 66.6666.
    $tercios2 = ArticlePurchasePresentation::factory()
        ->yielding('3.0000')
        ->create(['article_id' => $this->article->id]);

    $cost = $this->capture->fromPresentation($tercios2, '200.00');

    expect(bccomp($cost->unit_cost, '66.6667', 4))->toBe(0);
    expect(bccomp($cost->unit_cost, '66.6666', 4))->not->toBe(0);
});

it('una captura RETROACTIVA no pisa el costo vigente', function () {
    // La factura de la semana pasada que se registra hoy. Sin esta regla, capturar un costo viejo
    // dejaría al sistema costeando con él, y el error sería invisible: la proyección tendría un valor
    // perfectamente plausible.
    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->article, '30.0000', effectiveAt: CarbonImmutable::now());
    $this->capture->atUnitCost($this->article, '10.0000', effectiveAt: CarbonImmutable::now()->subDays(7));

    $projection = ArticleCurrentCost::query()->where('article_id', $this->article->id)->firstOrFail();

    expect(bccomp($projection->unit_cost, '30', 4))->toBe(0);

    // Pero el historial sí tiene las dos: la retroactiva no se pierde, sólo no es la vigente.
    expect(ArticleCost::query()->where('article_id', $this->article->id)->count())->toBe(2);
});

it('el evento dice si el costo quedó como vigente', function () {
    // El recálculo en cascada (paso 6) sólo debe dispararse cuando el costo vigente cambió: recostear
    // por una captura retroactiva que no es la vigente sería trabajo inútil con resultado equivocado.
    Event::fake([ArticleCostChanged::class]);

    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->article, '30.0000', effectiveAt: CarbonImmutable::now());
    $this->capture->atUnitCost($this->article, '10.0000', effectiveAt: CarbonImmutable::now()->subDays(7));

    Event::assertDispatched(
        ArticleCostChanged::class,
        fn (ArticleCostChanged $e): bool => $e->becameCurrent === true && bccomp($e->cost->unit_cost, '30', 4) === 0
    );

    Event::assertDispatched(
        ArticleCostChanged::class,
        fn (ArticleCostChanged $e): bool => $e->becameCurrent === false && bccomp($e->cost->unit_cost, '10', 4) === 0
    );
});

it('el historial es INMUTABLE por las tres vías', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $cost = $this->capture->atUnitCost($this->article, '24.0000');

    // 1. El modelo.
    expect(fn () => $cost->update(['unit_cost' => '1.0000']))->toThrow(ImmutableRecordException::class);
    expect(fn () => $cost->delete())->toThrow(ImmutableRecordException::class);

    // 2. El query builder, que NO dispara eventos y sería la puerta más ancha.
    expect(fn () => ArticleCost::query()->update(['unit_cost' => '1.0000']))
        ->toThrow(ImmutableRecordException::class);
    expect(fn () => ArticleCost::query()->delete())->toThrow(ImmutableRecordException::class);

    // 3. Y la fila sigue intacta.
    expect(bccomp($cost->fresh()->unit_cost, '24', 4))->toBe(0);
});

it('reintentar con la misma llave devuelve el costo que ya existe, sin duplicar', function () {
    // Esta prueba CAMBIÓ en la Iteración 3, paso 9, y conviene decir por qué.
    //
    // Antes exigía que el segundo intento **lanzara** `UniqueConstraintViolationException`, con este argumento: «el
    // índice único lo hace imposible aunque el código se equivoque, que es la diferencia entre una garantía y una buena
    // intención». El argumento es bueno y la garantía sigue intacta — la prueba de abajo la comprueba.
    //
    // Lo que se corrigió es el comportamiento del SERVICIO. Una llave de idempotencia significa «esta operación se
    // identifica así; aplicarla dos veces tiene que tener el efecto de aplicarla una». Bajo ese contrato, reintentar y
    // recibir el resultado que ya existe **es** lo correcto, y lanzar es una implementación incompleta: obliga a cada
    // llamador a atrapar la excepción y a reconocer códigos de error de MySQL para distinguir un reintento normal de un
    // fallo real.
    //
    // Lo destapó el paso 9: al confirmar una recepción, re-despachar el evento reventaba con un 500 en el costo
    // mientras el movimiento de kardex lo soportaba sin problema — dos mecanismos de idempotencia del mismo proyecto
    // comportándose distinto, que es la clase de trampa en la que cae quien escriba el tercero.
    app(TenantContext::class)->set($this->tenant->id);

    $primero = $this->capture->atUnitCost($this->article, '24.0000', idempotencyKey: 'recost:jitomate:1');

    $segundo = $this->capture->atUnitCost($this->article, '25.0000', idempotencyKey: 'recost:jitomate:1');

    // La MISMA fila, con el valor del primer intento. Una fila, no dos.
    expect($segundo->id)->toBe($primero->id)
        ->and(bccomp($segundo->unit_cost, '24', 4))->toBe(0)
        ->and(ArticleCost::query()->where('idempotency_key', 'recost:jitomate:1')->count())->toBe(1);

    // El «25» del segundo intento se IGNORA, y eso hay que dejarlo escrito: reintentar con la misma llave y datos
    // distintos es un error del llamador, y ninguno de los dos comportamientos —lanzar o devolver— lo detecta bien.
    // La llave identifica la operación; si los datos cambian, es otra operación y le toca otra llave.
    //
    // La proyección del costo vigente también se queda en 24: el segundo intento no escribió nada.
    expect(bccomp(
        (string) ArticleCurrentCost::query()->where('article_id', $this->article->id)->value('unit_cost'),
        '24',
        4,
    ))->toBe(0);
});

it('la garantía sigue siendo de la BASE: un segundo camino no puede duplicar la llave', function () {
    // Es la mitad que la prueba anterior defendía y que no se perdió. Que el servicio maneje el reintento con elegancia
    // no significa que la unicidad dependa de él: cualquier otro código que escriba `article_costs` —un seeder, una
    // migración de datos, un job futuro escrito de prisa— se topa con el índice.
    //
    // Se comprueba sin pasar por el servicio, que es justo el «aunque el código se equivoque» del argumento original.
    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->article, '24.0000', idempotencyKey: 'recost:jitomate:2');

    expect(fn () => ArticleCost::create([
        'article_id' => $this->article->id,
        'unit_cost' => '99.0000',
        'origin' => CostOrigin::Manual,
        'idempotency_key' => 'recost:jitomate:2',
        'effective_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(ArticleCost::query()->where('idempotency_key', 'recost:jitomate:2')->count())->toBe(1);
});

it('el promedio del periodo IGNORA los costos calculados (D14)', function () {
    // D14 define el costo vigente como "el último costo de adquisición". Promediar el costo calculado
    // de un platillo con el costo de compra de un insumo mezcla dos magnitudes y da un número que no
    // significa nada.
    app(TenantContext::class)->set($this->tenant->id);

    ArticleCost::factory()->costing('10.0000')->origin(CostOrigin::Manual)
        ->create(['article_id' => $this->article->id]);
    ArticleCost::factory()->costing('20.0000')->origin(CostOrigin::Purchase)
        ->create(['article_id' => $this->article->id]);
    ArticleCost::factory()->costing('999.0000')->origin(CostOrigin::RecipeCascade)
        ->create(['article_id' => $this->article->id]);

    app(TenantContext::class)->forget();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->article->ulid}/cost")
        ->assertOk()
        ->json('data');

    // (10 + 20) / 2 = 15. Si contara el calculado, saldría 343.
    expect((float) $data['period_average'])->toBe(15.0);
    expect($data['period_average_is_reference_only'])->toBeTrue();
});

it('sin costo capturado devuelve null y no cero', function () {
    // Cero diría que es gratis. La diferencia importa: un artículo sin costo no se puede costear ni
    // sugerirle precio, y la UI tiene que decirlo en lugar de mostrar un margen del 100 %.
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->article->ulid}/cost")
        ->assertOk()
        ->json('data');

    expect($data['unit_cost'])->toBeNull();
    expect($data['period_average'])->toBeNull();
});

it('la proyección NUNCA divergiría del historial, y hay comando para probarlo (P4)', function () {
    // Tercera condición de P4. Se fuerza la divergencia escribiendo historial crudo con la factory
    // —que a propósito no toca la proyección— y se comprueba que el verificador la detecta y que la
    // reconstrucción la arregla.
    app(TenantContext::class)->set($this->tenant->id);

    $this->capture->atUnitCost($this->article, '10.0000');

    $rebuild = app(RebuildCurrentCosts::class);

    expect($rebuild->divergences())->toBe([]);

    // Historial nuevo sin pasar por el servicio: exactamente el camino que podría divergir.
    ArticleCost::factory()->costing('99.0000')->create([
        'article_id' => $this->article->id,
        'effective_at' => CarbonImmutable::now()->addDay(),
    ]);

    $divergences = $rebuild->divergences();

    expect($divergences)->toHaveCount(1);
    expect($divergences[0]['article_id'])->toBe($this->article->id);

    $rebuild->forCurrentTenant();

    expect($rebuild->divergences())->toBe([]);
    expect(bccomp(
        ArticleCurrentCost::query()->where('article_id', $this->article->id)->value('unit_cost'),
        '99',
        4
    ))->toBe(0);
});

it('el comando de reconstrucción corre sobre todos los negocios', function () {
    $this->artisan('comandia:costs:rebuild')->assertSuccessful();

    // Y en modo verificación falla si hay divergencias, para poder colgarlo de un chequeo periódico.
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        ArticleCost::factory()->costing('50.0000')->create(['article_id' => $this->article->id]);
    });

    $this->artisan('comandia:costs:rebuild --check')->assertFailed();
    $this->artisan('comandia:costs:rebuild')->assertSuccessful();
    $this->artisan('comandia:costs:rebuild --check')->assertSuccessful();
});

it('captura de costo por API, con actor registrado', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/costs", [
            'unit_cost' => '24.5000',
            'notes' => 'Factura 1234 del proveedor',
        ])
        ->assertCreated();

    expect($respuesta->json('data.origin'))->toBe('manual');
    expect($respuesta->json('data.origin_label'))->toBe('Captura manual');
    expect($respuesta->json('data.is_acquisition'))->toBeTrue();

    app(TenantContext::class)->set($this->tenant->id);

    $cost = ArticleCost::query()->where('article_id', $this->article->id)->firstOrFail();

    // El actor real queda registrado: un costo es un dato del que alguien responde.
    expect($cost->actor_membership_id)->toBe($this->membership->id);
});

it('captura por API usando una presentación de compra', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $presentation = ArticlePurchasePresentation::factory()
        ->yielding('25.0000', 'Costal de 25 kg')
        ->create(['article_id' => $this->article->id]);

    app(TenantContext::class)->forget();

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/costs", [
            'presentation_ulid' => $presentation->ulid,
            'total_cost' => '600.00',
        ])
        ->assertCreated();

    expect(bccomp($respuesta->json('data.unit_cost'), '24', 4))->toBe(0);
});

it('RECHAZA capturar por unidad Y por presentación a la vez', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $presentation = ArticlePurchasePresentation::factory()->create(['article_id' => $this->article->id]);
    app(TenantContext::class)->forget();

    // Son dos afirmaciones que pueden contradecirse, y elegir una en silencio es la clase de decisión
    // que produce un costo equivocado sin que nadie lo note.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/costs", [
            'unit_cost' => '10.0000',
            'presentation_ulid' => $presentation->ulid,
            'total_cost' => '100.00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('unit_cost');
});

it('RECHAZA una presentación de OTRO artículo', function () {
    // Daría un costo unitario calculado con el factor equivocado: un número plausible y falso, que es
    // el peor resultado posible.
    app(TenantContext::class)->set($this->tenant->id);

    $otro = Article::factory()->create(['name' => 'Cebolla']);
    $presentation = ArticlePurchasePresentation::factory()->create(['article_id' => $otro->id]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/costs", [
            'presentation_ulid' => $presentation->ulid,
            'total_cost' => '100.00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('presentation_ulid');
});

it('un MESERO no ve costos; un ALMACENISTA sí los captura', function () {
    // El costo es información sensible del negocio: quien toma la orden no necesita el margen. Quien
    // recibe la mercancía sí, porque tiene la factura en la mano (D71).
    $roles = app(TenantContext::class)->runFor($this->tenant->id, fn (): array => [
        'mesero' => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail(),
        'almacenista' => Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail(),
    ]);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($roles): void {
        $this->owner->assignRole($roles['mesero']);
        $this->owner->assignRole($roles['almacenista']);
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $roles['mesero']->ulid)
        ->getJson("/api/v1/articles/{$this->article->ulid}/cost")
        ->assertStatus(403);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $roles['almacenista']->ulid)
        ->postJson("/api/v1/articles/{$this->article->ulid}/costs", ['unit_cost' => '24.0000'])
        ->assertCreated();
});

it('los costos de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        $ajeno = Article::factory()->create(['name' => 'Café ajeno']);
        ArticleCost::factory()->costing('777.0000')->create(['article_id' => $ajeno->id]);
    });

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(ArticleCost::query()->count())->toBe(0);
        expect(ArticleCurrentCost::query()->count())->toBe(0);
    });
});
