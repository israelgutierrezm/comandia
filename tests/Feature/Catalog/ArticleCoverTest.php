<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * PORTADA DEL ARTÍCULO EN EL POS (sonda ArticleCoverProbe)
 *
 * El grid del POS pinta la foto del producto. La foto vive en `Publishing` (galería); el catálogo la pregunta por la sonda
 * del kernel sin nombrar a `Publishing`. Se prueba de punta a punta: subir la foto por el endpoint real de Publishing y
 * verificar que `/articles` (Catalog) devuelve `image_url` con la portada (1.ª por sort_order), y `null` sin foto.
 */
beforeEach(function () {
    Storage::fake('public');

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

afterEach(fn () => app(TenantContext::class)->forget());

function crearArticulo(string $name): string
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson('/api/v1/articles', [
            'name' => $name,
            'base_unit_ulid' => test()->kg->ulid,
            'is_sellable' => true,
            'is_inventoriable' => false,
            'is_supply' => false,
            'is_producible' => false,
            'base_price' => '100.00',
            'category_ulid' => test()->category->ulid,
        ])->assertCreated()->json('data.ulid');
}

it('el catálogo del POS expone la portada (1.ª foto) y null sin foto', function () {
    $conFoto = crearArticulo('Hamburguesa');
    $sinFoto = crearArticulo('Agua');

    // La primera foto (sort_order 0) es la portada; se guarda su URL para comparar.
    $portada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->post("/api/v1/articles/{$conFoto}/publication/images", ['image' => UploadedFile::fake()->image('portada.jpg')])
        ->assertCreated()
        ->json('data.images.0.url');

    // Una segunda foto NO debe cambiar la portada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->post("/api/v1/articles/{$conFoto}/publication/images", ['image' => UploadedFile::fake()->image('segunda.jpg')])
        ->assertCreated();

    $data = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/articles?available_in_pos=1&per_page=100')
        ->assertOk()
        ->json('data');

    $mapa = collect($data)->keyBy('ulid');

    expect($portada)->not->toBeNull();
    expect($mapa[$conFoto]['image_url'])->toBe($portada);
    expect($mapa[$sinFoto]['image_url'])->toBeNull();
});
