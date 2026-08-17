<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Exceptions\ArticleInvariantException;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * EL ARTÍCULO UNIFICADO (D17)
 *
 * Lo que hace interesante esta suite no es el CRUD: son las **combinaciones de capacidades**. D17
 * prohíbe tablas separadas de productos e insumos porque en un restaurante la misma cosa es varias a
 * la vez, y el precio de esa decisión es que la validación tiene que razonar sobre combinaciones en
 * lugar de sobre un `type`.
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

    $this->kg = Unit::query()->where('code', 'kg')->firstOrFail();
    $this->category = ArticleCategory::factory()->create(['name' => 'Platillos']);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

function crear(array $overrides = []): array
{
    return array_merge([
        'name' => 'Enchiladas suizas',
        'base_unit_ulid' => test()->kg->ulid,
        'is_sellable' => true,
        'is_inventoriable' => false,
        'is_supply' => false,
        'is_producible' => true,
        'base_price' => '120.00',
        'category_ulid' => test()->category->ulid,
    ], $overrides);
}

it('crea un platillo: vendible y producible, ni inventariable ni insumo', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear())
        ->assertCreated();

    expect($respuesta->json('data.capabilities'))->toBe([
        'sellable' => true,
        'inventoriable' => false,
        'supply' => false,
        'producible' => true,
    ]);

    expect($respuesta->json('data.base_price'))->toBe('120.00');
    expect($respuesta->json('data.ulid'))->toHaveLength(26);
});

it('crea una cerveza: vendible, inventariable Y insumo a la vez', function () {
    // El caso que justifica D17. Con tablas separadas de productos e insumos, esta cerveza tendría que
    // existir dos veces y su existencia se llevaría en una de las dos.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear([
            'name' => 'Cerveza clara 355 ml',
            'is_sellable' => true,
            'is_inventoriable' => true,
            'is_supply' => true,
            'is_producible' => false,
            'base_price' => '45.00',
        ]))
        ->assertCreated();

    expect($respuesta->json('data.capabilities'))->toBe([
        'sellable' => true,
        'inventoriable' => true,
        'supply' => true,
        'producible' => false,
    ]);
});

it('crea un insumo sin precio ni categoría', function () {
    // La harina no se vende y no necesita categoría de venta. Exigirle las dos cosas sería tratar el
    // catálogo como si todo fuera producto de venta.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', [
            'name' => 'Harina de trigo',
            'base_unit_ulid' => $this->kg->ulid,
            'is_sellable' => false,
            'is_inventoriable' => true,
            'is_supply' => true,
            'is_producible' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.base_price', null);
});

it('RECHAZA un vendible sin precio (invariante I2)', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear(['base_price' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('base_price');
});

it('RECHAZA un vendible sin categoría (P11)', function () {
    // El POS agrupa la pantalla por categoría: un vendible sin categoría no tendría dónde aparecer.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear(['category_ulid' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_ulid');
});

it('RECHAZA un artículo sin ninguna capacidad', function () {
    // No se vende, no se inventaría, no es insumo y no se produce: es una fila que nadie puede usar y
    // que el usuario creyó que servía.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear([
            'is_sellable' => false,
            'is_inventoriable' => false,
            'is_supply' => false,
            'is_producible' => false,
            'base_price' => null,
            'category_ulid' => null,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('is_sellable');
});

it('los invariantes valen también fuera de HTTP', function () {
    // Un Form Request protege el camino HTTP; la primera importación masiva de catálogo entra por
    // otro lado. Por eso el modelo los impone al guardar.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => Article::factory()->create([
        'is_sellable' => true,
        'base_price' => null,
        'category_id' => $this->category->id,
    ]))->toThrow(ArticleInvariantException::class);

    expect(fn () => Article::factory()->create([
        'is_sellable' => true,
        'base_price' => '10.00',
        'category_id' => null,
    ]))->toThrow(ArticleInvariantException::class);
});

it('la unidad base NO se puede cambiar (invariante I6, forma estricta)', function () {
    // Todas las cantidades históricas del artículo están expresadas en esa unidad. Cambiarla no
    // corrige un error: reinterpreta el pasado.
    //
    // Es MÁS estricto que el diseño original ("no cambia si tiene costos, recetas o movimientos")
    // porque P1 hace esa versión imposible de imponer desde `Catalog`: averiguar si tiene costos
    // sería preguntarle a `Costing`.
    app(TenantContext::class)->set($this->tenant->id);

    $article = Article::factory()->create(['base_unit_id' => $this->kg->id]);
    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    expect(fn () => $article->update(['base_unit_id' => $gramo->id]))
        ->toThrow(ArticleInvariantException::class);

    // Y la fila no cambió.
    expect($article->fresh()->base_unit_id)->toBe($this->kg->id);
});

it('el código es opcional y único cuando existe (P10)', function () {
    $spa = $this->actingAsSpa($this->owner, $this->tenant->id);

    // Dos artículos sin código: MySQL no deduplica NULL en índices únicos, que aquí es lo deseado.
    $spa->postJson('/api/v1/articles', crear(['name' => 'Uno']))->assertCreated();
    $spa->postJson('/api/v1/articles', crear(['name' => 'Dos']))->assertCreated();

    $spa->postJson('/api/v1/articles', crear(['name' => 'Tres', 'code' => 'ENCH-01']))->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear(['name' => 'Cuatro', 'code' => 'ENCH-01']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('el nombre corto es el que se imprime, y cae al largo si falta', function () {
    // Una comanda es papel de 58 mm: dejar que el POS trunque produce comandas ambiguas para la
    // cocina, y ahí una ambigüedad cuesta un platillo.
    $spa = $this->actingAsSpa($this->owner, $this->tenant->id);

    $conCorto = $spa->postJson('/api/v1/articles', crear([
        'name' => 'Enchiladas suizas de pollo con frijoles refritos',
        'short_name' => 'Ench. suizas',
    ]))->json('data');

    expect($conCorto['display_name'])->toBe('Ench. suizas');

    $sinCorto = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear(['name' => 'Chilaquiles']))
        ->json('data');

    expect($sinCorto['display_name'])->toBe('Chilaquiles');
});

it('archivar deja el artículo fuera del POS', function () {
    // Un artículo archivado que siguiera disponible sería una contradicción que alguien descubriría
    // al intentar venderlo.
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear())
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'archived')
        ->assertJsonPath('data.is_available_in_pos', false);
});

it('el listado filtra por categoría INCLUYENDO subcategorías', function () {
    // Quien filtra por "Bebidas" espera ver también las de "Bebidas > Cervezas". Lo contrario
    // obligaría al cliente a conocer el árbol y a mandar N identificadores.
    app(TenantContext::class)->set($this->tenant->id);

    $bebidas = ArticleCategory::factory()->create(['name' => 'Bebidas']);
    $cervezas = ArticleCategory::factory()->childOf($bebidas)->create(['name' => 'Cervezas']);

    Article::factory()->sellable()->create(['name' => 'Agua', 'category_id' => $bebidas->id]);
    Article::factory()->sellable()->create(['name' => 'Lager', 'category_id' => $cervezas->id]);
    Article::factory()->sellable()->create(['name' => 'Sopa', 'category_id' => $this->category->id]);

    app(TenantContext::class)->forget();

    $nombres = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles?category={$bebidas->ulid}")
        ->assertOk()
        ->json('data.*.name');

    expect($nombres)->toContain('Agua', 'Lager');
    expect($nombres)->not->toContain('Sopa');
});

it('el listado filtra por capacidad y RECHAZA una capacidad inventada', function () {
    app(TenantContext::class)->set($this->tenant->id);
    Article::factory()->create(['name' => 'Harina']);
    Article::factory()->sellable()->create(['name' => 'Sopa', 'category_id' => $this->category->id]);
    app(TenantContext::class)->forget();

    $insumos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?capability=supply')
        ->assertOk()
        ->json('data.*.name');

    expect($insumos)->toContain('Harina');
    expect($insumos)->not->toContain('Sopa');

    // Whitelist: un valor desconocido no se convierte en un `where` sobre una columna arbitraria.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?capability=inventado')
        ->assertStatus(422);
});

it('un filtro que no está en la whitelist devuelve 422 y no una lista completa', function () {
    // Ignorarlo devolvería una lista completa a quien cree estar filtrando: el peor resultado
    // posible, porque parece correcto.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?base_unit_id=1')
        ->assertStatus(422);
});

it('etiqueta un artículo y el pivote lleva su tenant', function () {
    // `article_tag.tenant_id` es NOT NULL por la Regla A, y `sync()` sólo escribe las dos llaves de la
    // relación: sin `withPivotValue` esto fallaría con un error de columna nula (mismo tropiezo que
    // D82 con el pivote de Spatie).
    app(TenantContext::class)->set($this->tenant->id);
    $vegana = Tag::factory()->create(['name' => 'Vegana']);
    app(TenantContext::class)->forget();

    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/articles', crear(['tag_ulids' => [$vegana->ulid]]))
        ->assertCreated()
        ->json('data.ulid');

    app(TenantContext::class)->set($this->tenant->id);

    $article = Article::findByUlid($ulid);

    expect($article->tags()->pluck('name')->all())->toBe(['Vegana']);

    $fila = DB::table('article_tag')->where('article_id', $article->id)->first();

    expect($fila->tenant_id)->toBe($this->tenant->id);
});

it('un MESERO no puede crear artículos', function () {
    // Verifica ROL ACTIVO y no suma de roles (D9).
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->owner->assignRole($mesero));

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson('/api/v1/articles', crear())
        ->assertStatus(403);

    // Y sí puede verlos: los precios los dice en voz alta.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/articles')
        ->assertOk();
});

it('los artículos de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        Article::factory()->create(['name' => 'Café en grano ajeno']);
    });

    app(TenantContext::class)->forget();

    $nombres = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles')
        ->assertOk()
        ->json('data.*.name');

    expect($nombres)->not->toContain('Café en grano ajeno');
});
