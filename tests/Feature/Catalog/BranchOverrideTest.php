<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleBranchOverride;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\PriceChange;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * OVERRIDES POR SUCURSAL (§6.1)
 *
 * Cascada de dos niveles: override de sucursal → dato maestro. Lo que hace interesante esta suite no es el
 * CRUD, son tres cosas: que el precio por sucursal se historice igual que el maestro, que "hereda" y "vale lo
 * mismo" sean estados distinguibles, y que el alcance de sucursal se respete — el `tenant_id` protege del
 * negocio ajeno, no de la sucursal ajena dentro del propio.
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
    $this->matriz = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);

    $this->pza = Unit::query()->where('code', 'pza')->firstOrFail();
    $this->categoria = ArticleCategory::factory()->create(['name' => 'Platillos']);

    $this->sur = Branch::factory()->create(['code' => 'SUR', 'name' => 'Sucursal Sur']);

    $this->articulo = Article::factory()->sellable('85.00')->create([
        'name' => 'Enchiladas',
        'base_unit_id' => $this->pza->id,
        'category_id' => $this->categoria->id,
    ]);

    app(CaptureArticleCost::class)->atUnitCost($this->articulo, '20.0000');

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('fija el precio propio de una sucursal y lo historiza con su branch_id', function () {
    // `price_changes.branch_id` existe desde el paso 8 precisamente para esto: "¿por qué esta sucursal cobra
    // $95 y las demás $85?" merece la misma respuesta que el precio maestro.
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", [
            'price' => '95.00',
            'reason' => 'Zona con renta más alta',
        ])
        ->assertOk()
        ->json('data');

    expect($data['master_price'])->toBe('85.00');
    expect($data['branch_price'])->toBe('95.00');
    expect($data['price_is_overridden'])->toBeTrue();
    expect($data['effective_price'])->toBe('95.00');

    app(TenantContext::class)->set($this->tenant->id);

    $change = PriceChange::query()->whereNotNull('branch_id')->firstOrFail();

    expect($change->branch_id)->toBe($this->sur->id);
    expect(bccomp($change->previous_price, '85', 2))->toBe(0);
    expect(bccomp($change->new_price, '95', 2))->toBe(0);
    expect($change->reason)->toBe('Zona con renta más alta');
    expect($change->actor_membership_id)->toBe($this->membership->id);

    // Y el snapshot de costeo también, igual que en el maestro (D115).
    expect(bccomp($change->unit_cost_at_change, '20', 4))->toBe(0);
    expect($change->suggested_price)->not->toBeNull();
});

it('el precio MAESTRO no cambia al fijar el de una sucursal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(bccomp($this->articulo->refresh()->base_price, '85', 2))->toBe(0);
});

it('quitar el precio propio devuelve la sucursal al del negocio, y lo historiza', function () {
    // Se historiza porque eso es lo que la sucursal va a cobrar desde ahora, y el historial tiene que poder
    // explicar por qué bajó.
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    $data = $spa()->deleteJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price")
        ->assertOk()
        ->json('data');

    expect($data['branch_price'])->toBeNull();
    expect($data['price_is_overridden'])->toBeFalse();
    expect($data['effective_price'])->toBe('85.00');

    app(TenantContext::class)->set($this->tenant->id);

    // Dos filas de historial para esa sucursal: la que puso 95 y la que devolvió a 85.
    $cambios = PriceChange::query()
        ->where('branch_id', $this->sur->id)
        ->orderBy('id')
        ->get();

    expect($cambios)->toHaveCount(2);
    expect(bccomp($cambios[1]->new_price, '85', 2))->toBe(0);
    expect($cambios[1]->reason)->toBe('Vuelve al precio del negocio');
});

it('la fila del override se BORRA cuando ya no anula nada', function () {
    // Una fila que hereda todo es indistinguible de no tener override, y conservarla dejaría la pregunta
    // "¿esta sucursal tiene precio propio?" con dos respuestas para el mismo estado.
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(ArticleBranchOverride::query()->count())->toBe(1);
    app(TenantContext::class)->forget();

    $spa()->deleteJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price")->assertOk();

    app(TenantContext::class)->set($this->tenant->id);
    expect(ArticleBranchOverride::query()->count())->toBe(0);
});

it('la fila SOBREVIVE si todavía anula la disponibilidad', function () {
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
        'is_available_in_pos' => false,
    ])->assertOk();

    $spa()->deleteJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price")->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $override = ArticleBranchOverride::query()->firstOrFail();

    expect($override->price)->toBeNull();
    expect($override->is_available_in_pos)->toBeFalse();
});

it('apaga un platillo en una sucursal sin tocar el catálogo del negocio', function () {
    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
            'is_available_in_pos' => false,
        ])
        ->assertOk()
        ->json('data');

    expect($data['is_available_in_pos'])->toBeFalse();
    expect($data['effective_is_available_in_pos'])->toBeFalse();

    app(TenantContext::class)->set($this->tenant->id);

    // El negocio sigue teniéndolo disponible.
    expect($this->articulo->refresh()->is_available_in_pos)->toBeTrue();
});

it('null en disponibilidad vuelve a HEREDAR, y no es lo mismo que false', function () {
    // Es la razón de que la columna sea nullable: `false` dice "no está disponible aquí" y `null` dice "usa lo
    // del negocio". Sin la distinción, volver a heredar sería imposible.
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
        'is_available_in_pos' => false,
    ])->assertOk();

    $data = $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
        'is_available_in_pos' => null,
    ])->assertOk()->json('data');

    expect($data['is_available_in_pos'])->toBeNull();
    expect($data['effective_is_available_in_pos'])->toBeTrue();

    app(TenantContext::class)->set($this->tenant->id);

    // Y la fila se fue, porque ya no anulaba nada.
    expect(ArticleBranchOverride::query()->count())->toBe(0);
});

it('la disponibilidad por sucursal NO ensucia el historial de precios', function () {
    // No es un precio. Meterlo en `price_changes` contaminaría el historial que D15 define, y apagar un
    // platillo es reversible y no afecta a ningún documento pasado.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
            'is_available_in_pos' => false,
        ])
        ->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    expect(PriceChange::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Lectura del precio efectivo
// ---------------------------------------------------------------------------

it('el listado con ?branch devuelve el precio EFECTIVO de esa sucursal', function () {
    // Es la consulta del POS al pintar su pantalla.
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    $enSur = $spa()->getJson("/api/v1/articles?branch={$this->sur->ulid}")->assertOk()->json('data.0');

    expect($enSur['base_price'])->toBe('85.00');
    expect($enSur['effective_price'])->toBe('95.00');
    expect($enSur['effective_price_is_overridden'])->toBeTrue();

    // Y en la matriz, que no tiene override, el efectivo es el maestro.
    $enMatriz = $spa()->getJson("/api/v1/articles?branch={$this->matriz->ulid}")->assertOk()->json('data.0');

    expect($enMatriz['effective_price'])->toBe('85.00');
    expect($enMatriz['effective_price_is_overridden'])->toBeFalse();
});

it('sin ?branch el listado describe el dato MAESTRO y no inventa un efectivo', function () {
    // Es lo que edita la administración del catálogo. Devolver un "efectivo" sin sucursal obligaría a elegir
    // una arbitrariamente.
    $articulo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles')
        ->assertOk()
        ->json('data.0');

    expect($articulo)->not->toHaveKey('effective_price');
    expect($articulo['base_price'])->toBe('85.00');
});

it('un ?branch de OTRO negocio devuelve los datos maestros, no un error', function () {
    // No se confirma la existencia de un recurso ajeno, y el cliente ve el catálogo de su negocio en lugar de
    // un error sobre una sucursal que para él no existe.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    $articulo = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles?branch={$otro['branch']->ulid}")
        ->assertOk()
        ->json('data.0');

    expect($articulo)->not->toHaveKey('effective_price');
});

it('el resumen de overrides sólo lista las sucursales que tienen algo propio', function () {
    // Listar las cuarenta que no tienen nada sería ruido en el que se pierden las tres que sí.
    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '95.00'])
        ->assertOk();

    $data = $spa()->getJson("/api/v1/articles/{$this->articulo->ulid}/branch-overrides")
        ->assertOk()
        ->json('data');

    expect($data['master_price'])->toBe('85.00');
    expect($data['overrides'])->toHaveCount(1);
    expect($data['overrides'][0]['branch']['code'])->toBe('SUR');
    expect($data['overrides'][0]['price'])->toBe('95.00');
    expect($data['overrides'][0]['is_available_in_pos'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Alcance y autorización
// ---------------------------------------------------------------------------

it('RECHAZA tocar una sucursal fuera del ALCANCE de la membresía', function () {
    // El `tenant_id` protege del negocio ajeno, no de la sucursal ajena dentro del propio. Es el hueco que
    // `membership_branch_scopes` existe para cerrar, y hay que cerrarlo en el controlador porque el binding de
    // ruta resuelve cualquier sucursal del tenant.
    $gerente = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::MANAGER)->firstOrFail();

        $this->owner->assignRole($rol);

        // Se le quita el alcance total y se le deja sólo la matriz. Por la relación y no por el modelo
        // directamente: así la llave la pone Eloquent y no hay que acordarse de cómo se llama la columna.
        $this->membership->update(['has_all_branches' => false]);
        $this->membership->branchScopes()->create(['branch_id' => $this->matriz->id]);

        return $rol;
    });

    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $gerente->ulid)
        ->withHeader('X-Branch', $this->matriz->ulid);

    // En su sucursal, sí.
    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->matriz->ulid}/price", ['price' => '90.00'])
        ->assertOk();

    // En la ajena, no — ni el precio ni la disponibilidad.
    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '99.00'])
        ->assertStatus(403);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
        'is_available_in_pos' => false,
    ])->assertStatus(403);

    app(TenantContext::class)->set($this->tenant->id);

    expect(ArticleBranchOverride::query()->where('branch_id', $this->sur->id)->exists())->toBeFalse();
});

it('un MESERO no cambia precios ni disponibilidad por sucursal', function () {
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $spa = fn () => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/price", ['price' => '99.00'])
        ->assertStatus(403);

    $spa()->putJson("/api/v1/articles/{$this->articulo->ulid}/branches/{$this->sur->ulid}/availability", [
        'is_available_in_pos' => false,
    ])->assertStatus(403);
});

it('los overrides de otro negocio son invisibles', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    app(TenantContext::class)->runFor($otro['tenant']->id, function () use ($otro): void {
        $pza = Unit::query()->where('code', 'pza')->firstOrFail();
        $categoria = ArticleCategory::factory()->create(['name' => 'Ajena']);

        $ajeno = Article::factory()->sellable('50.00')->create([
            'name' => 'Café ajeno',
            'base_unit_id' => $pza->id,
            'category_id' => $categoria->id,
        ]);

        ArticleBranchOverride::create([
            'article_id' => $ajeno->id,
            'branch_id' => $otro['branch']->id,
            'price' => '60.00',
        ]);
    });

    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        expect(ArticleBranchOverride::query()->count())->toBe(0);
    });
});
