<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * MODIFICADORES EN LA CAPTURA (punto 4) — el servidor decide.
 *
 * El modal previsualiza, pero las reglas de los grupos las hace cumplir el backend: obligatorio, mín/máx, cantidad
 * sólo donde el grupo la permite, y que la opción sea DEL artículo. Un modificador agotado no entra aunque siga en la
 * carta. La nota libre a cocina se congela en la línea.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Taquería del Centro',
        ownerEmail: 'ana@taco.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $unidad = Unit::query()->where('code', 'pza')->sole();
    $cat = ArticleCategory::create(['name' => 'Platillos', 'level' => 1]);

    $this->chilaquiles = Article::create([
        'name' => 'Chilaquiles verdes',
        'category_id' => $cat->id,
        'base_unit_id' => $unidad->id,
        'is_sellable' => true,
        'base_price' => '98.00',
        'is_available_in_pos' => true,
    ]);

    // Salsa: OBLIGATORIA, una sola (radios forzosos).
    $this->salsa = ModifierGroup::create([
        'name' => 'Salsa', 'is_required' => true, 'min_selections' => 1, 'max_selections' => 1, 'allows_quantity' => false,
    ]);
    $this->verde = Modifier::create(['modifier_group_id' => $this->salsa->id, 'name' => 'Verde', 'extra_price' => '0.00']);
    $this->roja = Modifier::create(['modifier_group_id' => $this->salsa->id, 'name' => 'Roja', 'extra_price' => '0.00']);

    // Preparación: OPCIONAL, múltiple; con una opción con precio y una AGOTADA.
    $this->prep = ModifierGroup::create([
        'name' => 'Preparación', 'is_required' => false, 'min_selections' => 0, 'max_selections' => null, 'allows_quantity' => false,
    ]);
    $this->sinCebolla = Modifier::create(['modifier_group_id' => $this->prep->id, 'name' => 'Sin cebolla', 'extra_price' => '0.00']);
    $this->queso = Modifier::create(['modifier_group_id' => $this->prep->id, 'name' => 'Queso extra', 'extra_price' => '15.00']);
    $this->agotado = Modifier::create(['modifier_group_id' => $this->prep->id, 'name' => 'Aguacate', 'extra_price' => '20.00', 'sold_out' => true]);

    // Extras: permite CANTIDAD (los 3 shots de D7).
    $this->extras = ModifierGroup::create([
        'name' => 'Extras', 'is_required' => false, 'min_selections' => 0, 'max_selections' => null, 'allows_quantity' => true,
    ]);
    $this->chile = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Chile toreado', 'extra_price' => '5.00']);

    // Un modificador de OTRO artículo, para probar que no se puede inyectar por fuera.
    $otro = ModifierGroup::create(['name' => 'Ajeno', 'is_required' => false, 'min_selections' => 0, 'allows_quantity' => false]);
    $this->ajeno = Modifier::create(['modifier_group_id' => $otro->id, 'name' => 'Opción ajena', 'extra_price' => '0.00']);

    $this->chilaquiles->modifierGroups()->attach([$this->salsa->id, $this->prep->id, $this->extras->id]);

    app(TenantContext::class)->forget();

    $this->abrir = fn (): string => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])
        ->assertCreated()->json('data.ulid');

    /** Captura un chilaquiles con lo que se pase (modifier_ulids, modifier_quantities, note). */
    $this->capturar = fn (string $cuenta, array $extra = []) => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", [
            'lines' => [array_merge(['article_ulid' => $this->chilaquiles->ulid, 'quantity' => '1'], $extra)],
        ]);
});

afterEach(fn () => app(TenantContext::class)->forget());

it('rechaza un grupo obligatorio sin elegir', function () {
    ($this->capturar)(($this->abrir)(), [])->assertStatus(409);
});

it('con la salsa obligatoria elegida, captura y congela el modificador', function () {
    $data = ($this->capturar)(($this->abrir)(), ['modifier_ulids' => [$this->verde->ulid]])
        ->assertCreated()->json('data');

    $item = collect($data['items'])->firstWhere('article_name', 'Chilaquiles verdes');

    expect($item['modifiers'])->toHaveCount(1)
        ->and($item['modifiers'][0]['name'])->toBe('Verde');
});

it('rechaza pasar del máximo de un grupo (salsa máx 1)', function () {
    ($this->capturar)(($this->abrir)(), ['modifier_ulids' => [$this->verde->ulid, $this->roja->ulid]])
        ->assertStatus(409);
});

it('rechaza un modificador agotado', function () {
    ($this->capturar)(($this->abrir)(), ['modifier_ulids' => [$this->verde->ulid, $this->agotado->ulid]])
        ->assertStatus(409);
});

it('rechaza cantidad > 1 en un grupo que no la permite', function () {
    // La regla `allows_quantity` la hace cumplir el Form Request (422, con error por campo), que corre antes del servicio.
    ($this->capturar)(($this->abrir)(), [
        'modifier_ulids' => [$this->verde->ulid, $this->queso->ulid],
        'modifier_quantities' => [$this->queso->ulid => 2],
    ])->assertStatus(422);
});

it('acepta cantidad en un grupo que la permite y la suma al precio', function () {
    // 3 chiles × $5 = $15; total = 98 + 15.
    $data = ($this->capturar)(($this->abrir)(), [
        'modifier_ulids' => [$this->verde->ulid, $this->chile->ulid],
        'modifier_quantities' => [$this->chile->ulid => 3],
    ])->assertCreated()->json('data');

    expect($data['totals']['total'])->toBe('113.00');
});

it('rechaza un modificador que no es del artículo', function () {
    ($this->capturar)(($this->abrir)(), ['modifier_ulids' => [$this->verde->ulid, $this->ajeno->ulid]])
        ->assertStatus(409);
});

it('guarda la nota a cocina y la devuelve en la línea', function () {
    $data = ($this->capturar)(($this->abrir)(), [
        'modifier_ulids' => [$this->verde->ulid],
        'note' => 'Sin picante, para el niño',
    ])->assertCreated()->json('data');

    $item = collect($data['items'])->firstWhere('article_name', 'Chilaquiles verdes');

    expect($item['note'])->toBe('Sin picante, para el niño');
});

// ---------------------------------------------------------------------------
// Exposición para el modal (4b)
// ---------------------------------------------------------------------------

it('el catálogo marca qué artículos tienen modificadores', function () {
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?available_in_pos=1&status=active&per_page=50')
        ->assertOk()->json('data');

    expect(collect($data)->firstWhere('name', 'Chilaquiles verdes')['has_modifier_groups'])->toBeTrue();
});

it('expone los grupos del artículo con sus reglas y el agotado marcado', function () {
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->chilaquiles->ulid}/modifier-groups")
        ->assertOk()->json('data');

    $salsa = collect($data)->firstWhere('name', 'Salsa');
    expect($salsa['is_required'])->toBeTrue()
        ->and($salsa['max_selections'])->toBe(1)
        ->and(collect($salsa['modifiers'])->pluck('name')->all())->toContain('Verde', 'Roja');

    $prep = collect($data)->firstWhere('name', 'Preparación');
    expect(collect($prep['modifiers'])->firstWhere('name', 'Aguacate')['sold_out'])->toBeTrue();

    expect(collect($data)->firstWhere('name', 'Extras')['allows_quantity'])->toBeTrue();
});
