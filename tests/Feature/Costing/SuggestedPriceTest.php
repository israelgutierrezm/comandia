<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Catalog\Application\ChangeArticlePrice;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Configuration\Application\Settings;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Costing\Application\SuggestPrice;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PRECIO SUGERIDO, MARGEN Y SEMÁFORO (D15, D13)
 *
 * Las tres autoridades de D15: el sistema **sugiere**, el humano **decide**, el historial **recuerda**. Y el
 * candado del glosario normativo hecho prueba: markup y margen no son sinónimos.
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

    $this->g = Unit::query()->where('code', 'g')->firstOrFail();
    $this->pza = Unit::query()->where('code', 'pza')->firstOrFail();
    $this->categoria = ArticleCategory::factory()->create(['name' => 'Platillos']);

    $this->suggest = app(SuggestPrice::class);
    $this->capture = app(CaptureArticleCost::class);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Un artículo vendible con costo capturado, en el contexto ya abierto. */
function vendibleConCosto(string $cost, ?string $price = '100.00', ?string $markup = null): Article
{
    $t = test();

    $article = Article::factory()->sellable($price)->create([
        'name' => 'Platillo '.fake()->unique()->numerify('###'),
        'base_unit_id' => $t->pza->id,
        'category_id' => $t->categoria->id,
        'markup_percent' => $markup,
    ]);

    $t->capture->atUnitCost($article, $cost);

    return $article;
}

it('MARKUP y MARGEN no son lo mismo: costo 100, markup 200 % → sugerido 300, margen 66.67 %', function () {
    // El candado del glosario normativo (D13, §7) hecho prueba ejecutable. Confundirlos hace que un negocio
    // crea que gana el triple de lo que gana.
    app(TenantContext::class)->set($this->tenant->id);

    $article = vendibleConCosto('100.0000', price: '300.00', markup: '200.00');

    $s = $this->suggest->for($article);

    expect(bccomp($s->suggestedPrice, '300', 2))->toBe(0);
    expect(bccomp($s->markupPercent, '200', 2))->toBe(0);

    // (300 − 100) / 300 = 66.67 %. NO es 200 %.
    expect(bccomp($s->marginPercent, '66.67', 2))->toBe(0);
    expect(bccomp($s->marginPercent, '200', 2))->not->toBe(0);
});

it('usa el markup del ARTÍCULO cuando lo tiene, y el del negocio cuando no (P6)', function () {
    // Dos niveles, no tres: la categoría queda diferida con deuda declarada.
    app(TenantContext::class)->set($this->tenant->id);

    $conOverride = vendibleConCosto('10.0000', markup: '50.00');
    $sinOverride = vendibleConCosto('10.0000');

    $a = $this->suggest->for($conOverride);
    $b = $this->suggest->for($sinOverride);

    expect(bccomp($a->markupPercent, '50', 2))->toBe(0);
    expect($a->markupIsOverride)->toBeTrue();
    expect(bccomp($a->suggestedPrice, '15', 2))->toBe(0);

    // El default del catálogo de configuración es 200 %.
    expect(bccomp($b->markupPercent, '200', 2))->toBe(0);
    expect($b->markupIsOverride)->toBeFalse();
    expect(bccomp($b->suggestedPrice, '30', 2))->toBe(0);
});

it('el redondeo configurado se aplica al sugerido, y se explica', function () {
    app(TenantContext::class)->set($this->tenant->id);

    app(Settings::class)->setForTenant('pricing.rounding_mode', 'multiple_5');

    // costo 15.50 × 3 = 46.50 → a múltiplos de 5, 50.
    $article = vendibleConCosto('15.5000', markup: '200.00');

    $s = $this->suggest->for($article);

    expect(bccomp($s->suggestedPrice, '50', 2))->toBe(0);

    // El crudo antes de redondear viaja también: juntos explican por qué el sugerido no es exactamente
    // costo × (1 + markup).
    expect(bccomp($s->rawSuggestedPrice, '46.5', 4))->toBe(0);
    expect($s->rounding->value)->toBe('multiple_5');
    expect($s->rounding->label)->toBe('A múltiplos de $5');
});

it('el precio de un artículo con RECETA sale del costeo en cascada', function () {
    // La conexión entre los pasos 6 y 8: el sugerido de un platillo se calcula desde su receta.
    app(TenantContext::class)->set($this->tenant->id);

    $harina = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $this->capture->atUnitCost($harina, '0.0200');

    $galleta = Article::factory()->sellable('1.00')->producible()->create([
        'name' => 'Galleta',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
        'markup_percent' => '100.00',
    ]);

    // 50 g de harina a 0.02 = 1.00, rinde 4 → 0.25 por galleta. Con markup 100 % → sugerido 0.50.
    app(SaveRecipe::class)->save($galleta, [
        ['component_article_id' => $harina->id, 'quantity' => '50.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '4.0000', outputUnitId: $this->pza->id);

    $s = $this->suggest->for($galleta->refresh());

    expect(bccomp($s->unitCost, '0.25', 4))->toBe(0);
    expect(bccomp($s->suggestedPrice, '0.5', 2))->toBe(0);
});

it('sin costo NO hay sugerencia, y dice qué falta', function () {
    // No se sugiere cero: invitaría a regalar el platillo. Y la lista de faltantes es lo accionable.
    app(TenantContext::class)->set($this->tenant->id);

    $sal = Article::factory()->create(['name' => 'Sal', 'base_unit_id' => $this->g->id]);

    $platillo = Article::factory()->sellable('50.00')->producible()->create([
        'name' => 'Sopa',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
    ]);

    app(SaveRecipe::class)->save($platillo, [
        ['component_article_id' => $sal->id, 'quantity' => '5.0000', 'unit_id' => $this->g->id],
    ], outputQuantity: '1.0000', outputUnitId: $this->pza->id);

    $s = $this->suggest->for($platillo->refresh());

    expect($s->isComputable())->toBeFalse();
    expect($s->suggestedPrice)->toBeNull();
    expect($s->marginPercent)->toBeNull();
    expect($s->isStale)->toBeFalse();
    expect($s->missingCosts)->toBe(['Sal']);
});

// ---------------------------------------------------------------------------
// Semáforo (D15, P13)
// ---------------------------------------------------------------------------

it('el semáforo respeta la tolerancia configurada', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Sugerido 30 con markup 200 % sobre costo 10. Tolerancia por defecto: 5 %.
    // Precio 29 → desviación −3.33 %, dentro de tolerancia.
    $dentro = vendibleConCosto('10.0000', price: '29.00');

    expect($this->suggest->for($dentro)->isStale)->toBeFalse();

    // Precio 20 → desviación −33.33 %, fuera.
    $fuera = vendibleConCosto('10.0000', price: '20.00');

    $s = $this->suggest->for($fuera);

    expect($s->isStale)->toBeTrue();
    expect(bccomp($s->deviationPercent, '-33.33', 2))->toBe(0);
    expect(bccomp($s->tolerancePercent, '5', 2))->toBe(0);
});

it('el semáforo mira el VALOR ABSOLUTO de la desviación', function () {
    // Un precio muy por encima del sugerido también está desactualizado. El que está por debajo cuesta
    // dinero; el que está por encima ahuyenta clientes. Los dos merecen la señal.
    app(TenantContext::class)->set($this->tenant->id);

    $caro = vendibleConCosto('10.0000', price: '60.00');

    $s = $this->suggest->for($caro);

    expect($s->isStale)->toBeTrue();
    expect(bccomp($s->deviationPercent, '100', 2))->toBe(0);
});

it('subir la tolerancia apaga el semáforo (P13)', function () {
    // Es la razón de ser del ajuste: sin umbral configurable, el redondeo del propio tenant marcaría en rojo
    // el catálogo entero el primer día, y un semáforo siempre en rojo no lo mira nadie.
    app(TenantContext::class)->set($this->tenant->id);

    $article = vendibleConCosto('10.0000', price: '20.00');

    expect($this->suggest->for($article)->isStale)->toBeTrue();

    // Un número y no una cadena: `SettingType::Decimal` exige `int|float` al escribir. Es deliberado —
    // aceptar "abc" por parecer texto numérico sería peor— y la API HTTP responde 422 con el motivo.
    app(Settings::class)->setForTenant('pricing.stale_price_tolerance_percent', 50.0);

    expect($this->suggest->for($article->refresh())->isStale)->toBeFalse();
});

it('un artículo SIN precio no está desactualizado: está sin precio', function () {
    // Marcarlo en rojo llenaría el semáforo de artículos que nadie intentó cobrar todavía.
    app(TenantContext::class)->set($this->tenant->id);

    $insumo = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    $this->capture->atUnitCost($insumo, '0.0200');

    $s = $this->suggest->for($insumo);

    expect($s->currentPrice)->toBeNull();
    expect($s->isStale)->toBeFalse();
    expect($s->marginPercent)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cambio de precio e historial
// ---------------------------------------------------------------------------

it('cambiar el precio lo historiza con el estado del costeo del momento', function () {
    // Es la pregunta que el historial existe para contestar: ¿subió porque subió el costo, o porque alguien
    // quiso? Sin el costo y el markup del momento no tiene respuesta, porque esos dos ya cambiaron.
    app(TenantContext::class)->set($this->tenant->id);
    $article = vendibleConCosto('10.0000', price: '30.00');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$article->ulid}/price", [
            'price' => '45.00',
            'reason' => 'Subió el proveedor',
        ])
        ->assertOk()
        ->assertJsonPath('data.current_price', '45.00');

    app(TenantContext::class)->set($this->tenant->id);

    $change = PriceChange::query()->where('article_id', $article->id)->firstOrFail();

    expect(bccomp($change->previous_price, '30', 2))->toBe(0);
    expect(bccomp($change->new_price, '45', 2))->toBe(0);
    expect(bccomp($change->suggested_price, '30', 2))->toBe(0);
    expect(bccomp($change->unit_cost_at_change, '10', 4))->toBe(0);
    expect(bccomp($change->markup_percent, '200', 2))->toBe(0);
    expect($change->reason)->toBe('Subió el proveedor');
    expect($change->actor_membership_id)->toBe($this->membership->id);
    expect($change->branch_id)->toBeNull();

    // Y el artículo quedó con el precio nuevo.
    expect(bccomp($article->refresh()->base_price, '45', 2))->toBe(0);
});

it('el margen del historial se calcula, no se almacena (D13)', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $article = vendibleConCosto('10.0000', price: '30.00');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$article->ulid}/price", ['price' => '40.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $change = PriceChange::query()->where('article_id', $article->id)->firstOrFail();

    // (40 − 10) / 40 = 75 %. La columna no existe: se deriva del precio y del costo guardados.
    expect(bccomp($change->marginPercent(), '75', 2))->toBe(0);
    expect($change->getAttributes())->not->toHaveKey('margin_percent');
});

it('la primera fijación distingue «sin precio» de «valía cero»', function () {
    app(TenantContext::class)->set($this->tenant->id);

    // Un artículo sin precio no puede ser vendible (I2), así que se marca vendible al fijarle el precio.
    $article = Article::factory()->create([
        'name' => 'Postre nuevo',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
    ]);

    $this->capture->atUnitCost($article, '5.0000');

    $article->update(['is_sellable' => true, 'base_price' => '20.00']);

    // Y ahora se cambia: la fila anterior no existe, así que ésta es la primera del historial.
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$article->ulid}/price", ['price' => '25.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $change = PriceChange::query()->where('article_id', $article->id)->firstOrFail();

    // El anterior era 20, no NULL: el `null` está reservado para cuando de verdad no había precio.
    expect(bccomp($change->previous_price, '20', 2))->toBe(0);
});

it('el historial es INMUTABLE por las tres vías', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $article = vendibleConCosto('10.0000', price: '30.00');

    app(ChangeArticlePrice::class)->change($article, '40.00');

    $change = PriceChange::query()->where('article_id', $article->id)->firstOrFail();

    expect(fn () => $change->update(['new_price' => '1.00']))
        ->toThrow(ImmutableRecordException::class);
    expect(fn () => $change->delete())
        ->toThrow(ImmutableRecordException::class);
    expect(fn () => PriceChange::query()->update(['new_price' => '1.00']))
        ->toThrow(ImmutableRecordException::class);
});

it('el cambio de precio queda en la bitácora técnica además del historial', function () {
    // Las dos capas de §6.7 son complementarias: el historial contesta "cómo evolucionó este precio", la
    // bitácora "quién tocó qué desde dónde".
    app(TenantContext::class)->set($this->tenant->id);
    $article = vendibleConCosto('10.0000', price: '30.00');
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$article->ulid}/price", ['price' => '45.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $entry = AuditEntry::query()
        ->where('action', AuditAction::PRICE_CHANGED)
        ->firstOrFail();

    expect($entry->before['base_price'])->toBe('30.00');
    expect($entry->after['base_price'])->toBe('45.00');
});

it('el historial por API se lee del presente al pasado', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $article = vendibleConCosto('10.0000', price: '30.00');
    app(TenantContext::class)->forget();

    foreach (['35.00', '40.00', '45.00'] as $precio) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->putJson("/api/v1/articles/{$article->ulid}/price", ['price' => $precio])
            ->assertOk();
    }

    $precios = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$article->ulid}/price-changes")
        ->assertOk()
        ->json('data.*.new_price');

    expect($precios[0])->toBe('45.00');
    expect($precios[2])->toBe('35.00');
});

it('RECHAZA ponerle precio a un artículo que no es vendible', function () {
    // Un precio en un insumo es un número que alguien va a leer como precio de venta.
    app(TenantContext::class)->set($this->tenant->id);
    $insumo = Article::factory()->create(['name' => 'Harina', 'base_unit_id' => $this->g->id]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$insumo->ulid}/price", ['price' => '10.00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('price');
});

it('un MESERO no cambia precios ni ve el sugerido', function () {
    // Ve el precio —lo dice en voz alta— y no el margen ni el sugerido, que son información del negocio.
    $mesero = app(TenantContext::class)->runFor(
        $this->tenant->id,
        fn (): Role => Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail()
    );

    $article = app(TenantContext::class)->runFor($this->tenant->id, function () use ($mesero): Article {
        $this->owner->assignRole($mesero);

        return vendibleConCosto('10.0000', price: '30.00');
    });

    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid);

    $spa()->putJson("/api/v1/articles/{$article->ulid}/price", ['price' => '99.00'])->assertStatus(403);
    $spa()->getJson("/api/v1/articles/{$article->ulid}/suggested-price")->assertStatus(403);
    $spa()->getJson("/api/v1/articles/{$article->ulid}/price-changes")->assertStatus(403);

    // Y el precio no cambió.
    app(TenantContext::class)->runFor($this->tenant->id, function () use ($article): void {
        expect(bccomp($article->refresh()->base_price, '30', 2))->toBe(0);
    });
});

it('los cambios de precio de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function (): void {
        $pza = Unit::query()->where('code', 'pza')->firstOrFail();
        $categoria = ArticleCategory::factory()->create(['name' => 'Ajena']);

        $article = Article::factory()->sellable('10.00')->create([
            'name' => 'Café ajeno',
            'base_unit_id' => $pza->id,
            'category_id' => $categoria->id,
        ]);

        app(ChangeArticlePrice::class)->change($article, '20.00');
    });

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(PriceChange::query()->count())->toBe(0);
    });
});
