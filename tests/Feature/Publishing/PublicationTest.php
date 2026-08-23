<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Publishing\Infrastructure\Models\ArticleImage;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * CAPA DE PUBLICACIÓN (Iteración 8, Tanda A, ADR-007)
 *
 * `Publishing` enriquece los artículos del Core (descripción larga, orden, visibilidad, galería) sin duplicarlos. La
 * editan quienes administran menús o tienda (`publishing.articles.manage`). Las fotos van al disco público. Aislada por
 * tenant como todo el dominio.
 */
beforeEach(function () {
    Storage::fake('public');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->set($this->tenant->id);
    $this->article = Article::create([
        'name' => 'Enchiladas',
        'category_id' => ArticleCategory::create(['name' => 'Fuertes', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '145.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('crea la publicación vacía al consultarla y la guarda', function () {
    // Consultar la publicación de un artículo sin publicación la crea vacía (para editarla).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->article->ulid}/publication")
        ->assertOk()
        ->assertJsonPath('data.is_visible', true)
        ->assertJsonPath('data.long_description', null);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->article->ulid}/publication", [
            'long_description' => 'Tres tortillas bañadas en salsa verde, gratinadas.',
            'sort_order' => 3,
            'is_visible' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.long_description', 'Tres tortillas bañadas en salsa verde, gratinadas.')
        ->assertJsonPath('data.sort_order', 3)
        ->assertJsonPath('data.is_visible', false);
});

it('sube una foto a la galería y la borra', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/publication/images", [
            'image' => UploadedFile::fake()->image('enchiladas.jpg', 800, 600),
            'alt_text' => 'Enchiladas suizas',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.images.0.alt_text', 'Enchiladas suizas')
        ->json('data.images.0.ulid');

    // El archivo quedó en el disco público.
    app(TenantContext::class)->set($this->tenant->id);
    $image = ArticleImage::query()->where('ulid', $ulid)->sole();
    Storage::disk('public')->assertExists($image->disk_path);
    app(TenantContext::class)->forget();

    // Borrarla quita la fila Y el archivo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson("/api/v1/publication-images/{$ulid}")
        ->assertNoContent();

    Storage::disk('public')->assertMissing($image->disk_path);
});

it('rechaza un archivo que no es imagen', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->article->ulid}/publication/images", [
            'image' => UploadedFile::fake()->create('lista.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422);
});

it('un rol sin publishing.articles.manage no edita la publicación', function () {
    app(TenantContext::class)->set($this->tenant->id);
    // Un rol propio, no una plantilla: ProvisionTenant ya creó «Mesero».
    $rol = Role::create(['name' => 'Solo captura', 'guard_name' => 'web']);
    $rol->givePermissionTo('pos.orders.create');
    $user = User::factory()->create();
    $membership = TenantMembership::factory()->create(['user_id' => $user->id, 'default_role_id' => $rol->id]);
    $membership->update(['has_all_branches' => true]);
    $user->assignRole($rol);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($user, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->article->ulid}/publication")
        ->assertStatus(403);
});

it('un negocio no ve ni toca la publicación de otro', function () {
    // Publicación en el negocio A.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/articles/{$this->article->ulid}/publication", ['is_visible' => true])
        ->assertOk();

    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    // El artículo del negocio A no existe para el negocio B: su ULID no se resuelve → 404.
    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/articles/{$this->article->ulid}/publication")
        ->assertNotFound();
});
