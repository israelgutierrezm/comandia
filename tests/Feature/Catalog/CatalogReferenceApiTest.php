<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * API DE LOS DATOS DE REFERENCIA DEL CATÁLOGO: UNIDADES, CATEGORÍAS Y ETIQUETAS
 *
 * ## Por qué existe este archivo
 *
 * Al cerrar la Iteración 2 se auditó qué endpoints **no se habían llamado nunca** en una prueba. Salieron
 * diecinueve, y entre ellos los CRUD completos de unidades, categorías y etiquetas: el dominio estaba
 * probado —conversiones, jerarquía de dos niveles, invariantes— y la **capa HTTP no**.
 *
 * No es un detalle de cobertura. Es exactamente el hueco por el que se colaron dos defectos de la
 * Iteración 1: el perfil laboral respondía 500 en `GET` y `PUT` porque sólo se probaba su `DELETE`, y
 * buscar con acentos reventaba siete listados porque ninguna prueba escribía una `ú`. Un endpoint sin
 * llamar no es una aserción débil: es código que nadie ha ejecutado.
 *
 * Lo que se prueba aquí es la capa que el dominio no cubre: la forma de la respuesta, la whitelist de
 * filtros, los permisos por ruta y las conversiones de ULID a llave interna.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda de Referencia',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Amparo',
        ownerPaternalSurname: 'Rentería',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/**
 * Asigna el rol plantilla pedido y lo devuelve, para probar por ROL ACTIVO (D9).
 *
 * `syncRoles` va dentro del contexto: los roles de Spatie usan el tenant como equipo, así que sin
 * contexto no sabe a qué negocio pertenece el rol.
 */
function conRolActivo(string $nombre): Role
{
    app(TenantContext::class)->set(test()->tenant->id);

    $rol = Role::query()->where('name', $nombre)->firstOrFail();
    test()->owner->syncRoles([$rol]);

    app(TenantContext::class)->forget();

    return $rol;
}

// ---------------------------------------------------------------- Unidades

it('lista las cinco unidades sembradas al dar de alta el negocio', function () {
    // D97: el listener de `TenantProvisioned` las siembra. Si esto falla, ningún tenant nuevo puede
    // capturar ni un artículo.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/units')
        ->assertOk()
        ->assertJsonCount(5, 'data');

    $codigos = collect($respuesta->json('data'))->pluck('code')->sort()->values()->all();

    expect($codigos)->toBe(['g', 'kg', 'l', 'ml', 'pza']);
});

it('el recurso de unidad manda el factor como CADENA y la magnitud con su etiqueta', function () {
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/units?dimension=mass')
        ->assertOk();

    $kg = collect($respuesta->json('data'))->firstWhere('code', 'kg');

    // Cadena y no número: es un DECIMAL(18,8) y es el factor que multiplica TODAS las cantidades del
    // sistema. Convertirlo a float en el JSON metería error justo ahí.
    expect($kg['factor_to_base'])->toBeString()->toBe('1000.00000000')
        // Identificador estable para el código, etiqueta en español para el humano (D87).
        ->and($kg['dimension'])->toBe('mass')
        ->and($kg['dimension_label'])->toBe('Masa')
        ->and($kg['is_system_base'])->toBeFalse();

    $g = collect($respuesta->json('data'))->firstWhere('code', 'g');
    expect($g['is_system_base'])->toBeTrue();
});

it('crea una unidad propia del negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/units', [
            'code' => 'caja12',
            'name' => 'Caja de 12',
            'dimension' => 'count',
            'factor_to_base' => '12',
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'caja12')
        ->assertJsonPath('data.factor_to_base', '12.00000000');
});

it('al editar una unidad NO se puede cambiar el factor', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $caja = Unit::factory()->create(['code' => 'caja', 'factor_to_base' => '12.00000000']);
    app(TenantContext::class)->forget();

    // Se manda el factor y el servidor lo IGNORA en lugar de aplicarlo: es la constante con la que se
    // convirtieron todas las cantidades ya capturadas, y cambiarla reinterpretaría el histórico entero.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/units/{$caja->ulid}", [
            'name' => 'Caja de doce',
            'factor_to_base' => '24',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Caja de doce')
        ->assertJsonPath('data.factor_to_base', '12.00000000');
});

it('la unidad de otro negocio no existe', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Ajena',
        ownerEmail: 'ajeno@cafe.mx',
        ownerFirstName: 'Iván',
        ownerPaternalSurname: 'Peña',
        plainPassword: 'contrasena-larga-2',
        branchCode: 'AJN',
    );

    app(TenantContext::class)->set($otro['tenant']->id);
    $ajena = Unit::factory()->create(['code' => 'barril']);
    app(TenantContext::class)->forget();

    // 404 y no 403: no se confirma la existencia de un recurso de otro negocio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/units/{$ajena->ulid}")
        ->assertNotFound();
});

it('el mesero LEE unidades y no las administra', function () {
    // D99: las unidades son dato de referencia y se leen con `catalog.articles.view`, porque cualquiera
    // que capture una receta las necesita. Administrarlas es otra cosa.
    $mesero = conRolActivo(RoleTemplates::WAITER);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/units')
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->postJson('/api/v1/units', [
            'code' => 'x', 'name' => 'X', 'dimension' => 'unit', 'factor_to_base' => '1',
        ])
        ->assertForbidden();
});

// ------------------------------------------------------- Categorías

it('devuelve el árbol de categorías con las hijas dentro y sin paginar', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $alimentos = ArticleCategory::factory()->create(['name' => 'Alimentos', 'level' => 1]);
    ArticleCategory::factory()->create(['name' => 'Antojitos', 'parent_id' => $alimentos->id, 'level' => 2]);
    ArticleCategory::factory()->create(['name' => 'Bebidas', 'level' => 1]);
    app(TenantContext::class)->forget();

    // Sin paginar a propósito: un selector jerárquico necesita el árbol completo, y paginarlo obligaría
    // al cliente a reconstruirlo entre páginas.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/article-categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alimentos')
        ->assertJsonPath('data.0.children.0.name', 'Antojitos')
        ->assertJsonMissing(['meta']);
});

it('crea una subcategoría y calcula su nivel en el servidor', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $raiz = ArticleCategory::factory()->create(['name' => 'Alimentos', 'level' => 1]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/article-categories', [
            'name' => 'Platos fuertes',
            'parent_ulid' => $raiz->ulid,
            'sort_order' => 20,
        ])
        ->assertCreated()
        // `level` NO llega del cliente: es redundante con `parent_id` y el CHECK de la tabla rechazaría
        // la fila si se contradijeran. Dejar que lo mandara sería dejarle producir un 500.
        ->assertJsonPath('data.level', 2)
        ->assertJsonPath('data.can_have_children', false);
});

it('rechaza una tercera generación: el árbol es de dos niveles (D18)', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $raiz = ArticleCategory::factory()->create(['name' => 'Alimentos', 'level' => 1]);
    $hija = ArticleCategory::factory()->create(['name' => 'Antojitos', 'parent_id' => $raiz->id, 'level' => 2]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/article-categories', [
            'name' => 'Tacos',
            'parent_ulid' => $hija->ulid,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['parent_ulid']]);
});

it('archiva una categoría y la muestra dada de baja', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $categoria = ArticleCategory::factory()->create(['name' => 'Temporada', 'level' => 1]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/article-categories/{$categoria->ulid}/archive")
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/article-categories/{$categoria->ulid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

// ------------------------------------------------------------ Etiquetas

it('crea, lista y borra etiquetas', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tags', ['name' => 'Vegetariano'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Vegetariano');

    app(TenantContext::class)->set($this->tenant->id);
    $etiqueta = Tag::query()->where('name', 'Vegetariano')->firstOrFail();
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // La ÚNICA entidad del catálogo que se borra de verdad: no aparece en ningún documento, así que
    // borrarla no deja un hueco en nada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/tags/{$etiqueta->ulid}")
        ->assertNoContent();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/tags')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('no admite dos etiquetas con el mismo nombre en el mismo negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tags', ['name' => 'Picante'])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tags', ['name' => 'Picante'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

it('el mismo nombre de etiqueta SÍ puede existir en otro negocio', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Cafetería Vecina',
        ownerEmail: 'vecina@cafe.mx',
        ownerFirstName: 'Nadia',
        ownerPaternalSurname: 'Cruz',
        plainPassword: 'contrasena-larga-2',
        branchCode: 'VEC',
    );

    // La etiqueta ajena se crea por modelo dentro de su contexto y no por API: lo que se comprueba es que
    // la regla `unique` del Form Request está acotada al tenant, y eso se ve mejor con la fila ajena ya
    // existiendo antes de la petición.
    app(TenantContext::class)->runFor(
        $otro['tenant']->id,
        fn () => Tag::create(['name' => 'Picante']),
    );

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/tags', ['name' => 'Picante'])
        ->assertCreated();
});
