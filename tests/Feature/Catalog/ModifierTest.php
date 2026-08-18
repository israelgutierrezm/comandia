<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CalculateArticleCost;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Infrastructure\Models\Recipe;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * MODIFICADORES (D7, §6.1)
 *
 * Tres cosas hacen interesante esta suite: que las reglas de selección no puedan quedar en un estado que impida
 * comandar, que un modificador tenga **impacto en receta** —«extra queso» consume queso— y que los grupos sean
 * compartidos, con lo que editar uno afecta a todos los artículos que lo usan.
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
    $this->categoria = ArticleCategory::factory()->create(['name' => 'Platillos']);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('crea un grupo con sus opciones', function () {
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $grupo = $spa()->postJson('/api/v1/modifier-groups', [
        'name' => 'Término de la carne',
        'is_required' => true,
        'min_selections' => 1,
        'max_selections' => 1,
    ])->assertCreated()->json('data');

    expect($grupo['is_required'])->toBeTrue();
    expect($grupo['has_selection_limit'])->toBeTrue();

    $spa()->postJson("/api/v1/modifier-groups/{$grupo['ulid']}/modifiers", [
        'name' => 'Término medio',
        'extra_price' => '0.00',
    ])->assertCreated()->assertJsonPath('data.is_paid', false);

    $spa()->postJson("/api/v1/modifier-groups/{$grupo['ulid']}/modifiers", [
        'name' => 'Extra queso',
        'extra_price' => '25.00',
    ])->assertCreated()->assertJsonPath('data.is_paid', true);

    $detalle = $spa()->getJson("/api/v1/modifier-groups/{$grupo['ulid']}")->assertOk()->json();

    expect($detalle['data']['modifiers'])->toHaveCount(2);
    expect($detalle['meta']['articles_using'])->toBe(0);
});

it('un grupo SIN límite lo dice explícitamente', function () {
    // `null` = sin límite, distinto de un número alto: "elige los que quieras" y "elige hasta 255" se cuentan
    // igual en la práctica, pero sólo el primero se puede explicar en una pantalla.
    $grupo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/modifier-groups', ['name' => 'Extras'])
        ->assertCreated()
        ->json('data');

    expect($grupo['max_selections'])->toBeNull();
    expect($grupo['has_selection_limit'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Reglas de selección: los estados que dejarían al POS sin poder comandar
// ---------------------------------------------------------------------------

it('RECHAZA un máximo menor que el mínimo', function () {
    // Ninguna selección sería válida y el POS no podría comandar el platillo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/modifier-groups', [
            'name' => 'Imposible',
            'min_selections' => 3,
            'max_selections' => 2,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('max_selections');
});

it('RECHAZA un grupo obligatorio con mínimo cero', function () {
    // No obligaría a nada: sería obligatorio de nombre.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/modifier-groups', [
            'name' => 'Falso obligatorio',
            'is_required' => true,
            'min_selections' => 0,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('min_selections');
});

it('al EDITAR, las reglas se evalúan sobre el estado final', function () {
    // Subir el mínimo sin tocar el máximo puede invalidar una combinación que era válida, y al editar sólo
    // llega lo que cambia.
    app(TenantContext::class)->set($this->tenant->id);
    $grupo = ModifierGroup::factory()->required(1, 2)->create(['name' => 'Término']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/modifier-groups/{$grupo->ulid}", ['min_selections' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors('max_selections');
});

it('la base RECHAZA las mismas contradicciones, no sólo la validación', function () {
    // La validación protege el camino HTTP; el CHECK protege importaciones y seeders.
    app(TenantContext::class)->set($this->tenant->id);

    expect(fn () => ModifierGroup::factory()->create([
        'min_selections' => 3,
        'max_selections' => 2,
    ]))->toThrow(QueryException::class);

    expect(fn () => ModifierGroup::factory()->create([
        'is_required' => true,
        'min_selections' => 0,
    ]))->toThrow(QueryException::class);
});

it('RECHAZA un precio adicional negativo (P14)', function () {
    // Un modificador que resta es un descuento, y los descuentos tienen permiso, motivo y actor propios
    // (§6.3). Permitirlos aquí sería una puerta para descontar sin dejar rastro.
    app(TenantContext::class)->set($this->tenant->id);
    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/modifier-groups/{$grupo->ulid}/modifiers", [
            'name' => 'Descuento encubierto',
            'extra_price' => '-10.00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('extra_price');
});

it('NO deja un grupo obligatorio sin ninguna opción activa', function () {
    // Sería un grupo que exige elegir de una lista vacía: el POS no podría comandar el platillo, y es la clase
    // de estado que se descubre en hora pico.
    app(TenantContext::class)->set($this->tenant->id);

    $grupo = ModifierGroup::factory()->required()->create(['name' => 'Término']);
    $unica = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Medio']);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/modifiers/{$unica->ulid}/archive")
        ->assertStatus(409);

    app(TenantContext::class)->set($this->tenant->id);

    expect($unica->refresh()->isActive())->toBeTrue();
});

it('SÍ deja dar de baja una opción si queda otra activa', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $grupo = ModifierGroup::factory()->required()->create(['name' => 'Término']);
    $medio = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Medio']);
    Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Bien cocido']);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/modifiers/{$medio->ulid}/archive")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

// ---------------------------------------------------------------------------
// Asignación a artículos: los grupos son compartidos
// ---------------------------------------------------------------------------

it('asigna grupos a un artículo EN ORDEN, y el pivote lleva su tenant', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $articulo = Article::factory()->sellable('120.00')->create([
        'name' => 'Arrachera',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
    ]);

    $termino = ModifierGroup::factory()->required()->create(['name' => 'Término']);
    $extras = ModifierGroup::factory()->create(['name' => 'Extras']);

    app(TenantContext::class)->forget();

    // El orden del arreglo ES el orden de presentación.
    $grupos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$articulo->ulid}/modifier-groups", [
            'modifier_group_ulids' => [$extras->ulid, $termino->ulid],
        ])
        ->assertOk()
        ->json('data');

    expect(array_column($grupos, 'name'))->toBe(['Extras', 'Término']);
    expect($grupos[0]['sort_order'])->toBe(0);
    expect($grupos[1]['sort_order'])->toBe(1);

    // Regla A: el pivote lleva `tenant_id` aunque sea alcanzable por FK (D82).
    app(TenantContext::class)->set($this->tenant->id);

    $filas = DB::table('article_modifier_group')->where('article_id', $articulo->id)->get();

    expect($filas)->toHaveCount(2);
    expect($filas->every(fn ($f): bool => $f->tenant_id === $this->tenant->id))->toBeTrue();
});

it('sincronizar con una lista vacía quita todos los grupos', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $articulo = Article::factory()->sellable('120.00')->create([
        'name' => 'Arrachera',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
    ]);

    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    $articulo->modifierGroups()->sync([$grupo->id => ['sort_order' => 0]]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$articulo->ulid}/modifier-groups", ['modifier_group_ulids' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('RECHAZA asignar un grupo de otro negocio en lugar de ignorarlo', function () {
    // Ignorarlo dejaría al cliente creyendo que asignó un grupo que no se asignó — el peor resultado, porque
    // la respuesta parecería correcta.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    $ajeno = app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn (): ModifierGroup => ModifierGroup::factory()->create(['name' => 'Ajeno'])
    );

    $articulo = app(TenantContext::class)->runFor($this->tenant->id, fn (): Article => Article::factory()
        ->sellable('120.00')
        ->create([
            'name' => 'Arrachera',
            'base_unit_id' => $this->pza->id,
            'category_id' => $this->categoria->id,
        ]));

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$articulo->ulid}/modifier-groups", [
            'modifier_group_ulids' => [$ajeno->ulid],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('modifier_group_ulids');
});

it('el detalle del grupo dice a cuántos artículos afecta un cambio', function () {
    // Es la información que hace responsable la edición de un grupo compartido: editar «Término de la carne»
    // afecta a los ocho cortes que lo usan.
    app(TenantContext::class)->set($this->tenant->id);

    $grupo = ModifierGroup::factory()->create(['name' => 'Término']);

    foreach (['Arrachera', 'Rib eye', 'Sirloin'] as $nombre) {
        $articulo = Article::factory()->sellable('120.00')->create([
            'name' => $nombre,
            'base_unit_id' => $this->pza->id,
            'category_id' => $this->categoria->id,
        ]);

        $articulo->modifierGroups()->sync([$grupo->id => ['sort_order' => 0]]);
    }

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/modifier-groups/{$grupo->ulid}")
        ->assertOk()
        ->assertJsonPath('meta.articles_using', 3);
});

// ---------------------------------------------------------------------------
// Receta y costo del modificador (D100 pagada)
// ---------------------------------------------------------------------------

it('un modificador tiene receta propia y su costo se calcula', function () {
    // §6.1: "impacto en receta por unidad". Sin esto, el platillo con extras costaría lo mismo que sin ellos.
    app(TenantContext::class)->set($this->tenant->id);

    $queso = Article::factory()->create(['name' => 'Queso', 'base_unit_id' => $this->g->id]);
    app(CaptureArticleCost::class)->atUnitCost($queso, '0.2500');

    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    $extraQueso = Modifier::factory()->costing('25.00')->create([
        'modifier_group_id' => $grupo->id,
        'name' => 'Extra queso',
    ]);

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/modifiers/{$extraQueso->ulid}/recipe", [
            'output_quantity' => '1.0000',
            'lines' => [
                ['component_ulid' => $queso->ulid, 'quantity' => '30.0000', 'unit_ulid' => $this->g->ulid],
            ],
        ])
        ->assertOk();

    // 30 g × 0.25 = 7.50 por aplicación.
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/modifiers/{$extraQueso->ulid}/cost")
        ->assertOk()
        ->json('data');

    expect($data['is_computable'])->toBeTrue();
    expect(bccomp($data['unit_cost'], '7.5', 4))->toBe(0);
    expect($data['extra_price'])->toBe('25.00');
    expect($data['lines'][0]['component_name'])->toBe('Queso');
});

it('la receta del modificador es el DUEÑO en la misma tabla, con article_id nulo', function () {
    // La deuda D100 pagada: el dueño es artículo XOR modificador, con CHECK de exclusividad.
    app(TenantContext::class)->set($this->tenant->id);

    $queso = Article::factory()->create(['name' => 'Queso', 'base_unit_id' => $this->g->id]);
    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    $extra = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Extra queso']);

    app(SaveRecipe::class)->saveForModifier($extra, [
        ['component_article_id' => $queso->id, 'quantity' => '30.0000', 'unit_id' => $this->g->id],
    ]);

    $receta = Recipe::query()->where('modifier_id', $extra->id)->firstOrFail();

    expect($receta->article_id)->toBeNull();
    expect($receta->belongsToModifier())->toBeTrue();

    // Rinde exactamente una aplicación, en unidad de conteo.
    expect(bccomp($receta->output_quantity, '1', 4))->toBe(0);
    expect($receta->outputUnit->dimension->value)->toBe('count');
});

it('la base RECHAZA una receta con los dos dueños, y una sin ninguno', function () {
    // El CHECK de exclusividad de D100. Es la alternativa a una relación polimórfica, y la razón es la
    // integridad referencial: con `owner_type`/`owner_id` nada impediría una receta huérfana.
    app(TenantContext::class)->set($this->tenant->id);

    $articulo = Article::factory()->producible()->create(['base_unit_id' => $this->g->id]);
    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    $modificador = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Extra']);

    expect(fn () => Recipe::factory()->create([
        'article_id' => $articulo->id,
        'modifier_id' => $modificador->id,
    ]))->toThrow(QueryException::class);

    expect(fn () => Recipe::factory()->create([
        'article_id' => null,
        'modifier_id' => null,
    ]))->toThrow(QueryException::class);
});

it('un modificador SIN receta cuesta cero, no «desconocido»', function () {
    // «Término medio» no gasta insumos. Confundir "cuesta cero" con "no se puede calcular" haría incalculable
    // el platillo entero por llevar un modificador que no consume nada.
    app(TenantContext::class)->set($this->tenant->id);

    $grupo = ModifierGroup::factory()->create(['name' => 'Término']);
    $medio = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Término medio']);

    $breakdown = app(CalculateArticleCost::class)->modifierBreakdown($medio);

    expect($breakdown->isComputable())->toBeTrue();
    expect(bccomp($breakdown->unitCost, '0', 4))->toBe(0);
    expect($breakdown->lines)->toBe([]);
});

it('el rendimiento por insumo también aplica en la receta de un modificador', function () {
    // La fórmula está escrita una sola vez, así que D21 vale igual: 200 g al 80 % cuestan como 250 g.
    app(TenantContext::class)->set($this->tenant->id);

    $cebolla = Article::factory()->create(['name' => 'Cebolla', 'base_unit_id' => $this->g->id]);
    app(CaptureArticleCost::class)->atUnitCost($cebolla, '0.0500');

    $grupo = ModifierGroup::factory()->create(['name' => 'Extras']);
    $extra = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Extra cebolla']);

    app(SaveRecipe::class)->saveForModifier($extra, [
        [
            'component_article_id' => $cebolla->id,
            'quantity' => '200.0000',
            'unit_id' => $this->g->id,
            'yield_percent' => '80.00',
        ],
    ]);

    // 200 × 0.05 = 10.00, ÷ 0.8 = 12.50.
    expect(bccomp(app(CalculateArticleCost::class)->modifierBreakdown($extra)->unitCost, '12.5', 4))->toBe(0);
});

it('un modificador sin receta devuelve 404 al pedirla', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $grupo = ModifierGroup::factory()->create(['name' => 'Término']);
    $medio = Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Medio']);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/modifiers/{$medio->ulid}/recipe")
        ->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Autorización y aislamiento
// ---------------------------------------------------------------------------

it('un MESERO ve los modificadores del artículo y no los administra', function () {
    // Los necesita para capturar la orden; cambiar sus reglas es administración de catálogo.
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $articulo = app(TenantContext::class)->runFor($this->tenant->id, fn (): Article => Article::factory()
        ->sellable('120.00')
        ->create([
            'name' => 'Arrachera',
            'base_unit_id' => $this->pza->id,
            'category_id' => $this->categoria->id,
        ]));

    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid);

    $spa()->getJson("/api/v1/articles/{$articulo->ulid}/modifier-groups")->assertOk();
    $spa()->getJson('/api/v1/modifier-groups')->assertOk();

    $spa()->postJson('/api/v1/modifier-groups', ['name' => 'Inventado'])->assertStatus(403);
    $spa()->putJson("/api/v1/articles/{$articulo->ulid}/modifier-groups", [
        'modifier_group_ulids' => [],
    ])->assertStatus(403);
});

it('los modificadores de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        $grupo = ModifierGroup::factory()->create(['name' => 'Ajeno']);
        Modifier::factory()->create(['modifier_group_id' => $grupo->id, 'name' => 'Opción ajena']);
    });

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(ModifierGroup::query()->count())->toBe(0);
        expect(Modifier::query()->count())->toBe(0);
    });
});
