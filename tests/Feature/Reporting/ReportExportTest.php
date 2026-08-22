<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Support\Facades\Storage;

/**
 * EXPORTACIÓN DE REPORTES (Iteración 7, Tanda B, ADR-006 regla 5)
 *
 * Prueban el flujo completo: pedir un export encola un job en `exports`, que reconstruye el contexto, corre el reporte y
 * escribe el archivo; el autor consulta el estado y descarga; un ajeno no puede descargarlo. En pruebas la cola es
 * síncrona, así que el job corre durante la petición.
 */
beforeEach(function () {
    Storage::fake('local');

    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'secreto-largo-123',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];
    $this->branch = $alta['branch'];

    app(TenantContext::class)->set($this->tenant->id);
    $this->terminal = Terminal::create(['branch_id' => $this->branch->id, 'code' => 'CAJA1', 'name' => 'Caja 1']);
    $this->cerveza = Article::create([
        'name' => 'Cerveza',
        'category_id' => ArticleCategory::create(['name' => 'Bebidas', 'level' => 1])->id,
        'base_unit_id' => Unit::query()->where('code', 'pza')->sole()->id,
        'is_sellable' => true,
        'base_price' => '116.00',
        'is_available_in_pos' => true,
    ]);
    app(TenantContext::class)->runFor($this->tenant->id, fn () => app(CaptureArticleCost::class)->atUnitCost($this->cerveza, '40.0000'));
    app(TenantContext::class)->forget();

    // Una venta, para que el reporte tenga una fila.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-sessions', ['terminal_ulid' => $this->terminal->ulid, 'opening_float' => '0.00'])->assertCreated();
    $cuenta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/pos-accounts', ['branch_ulid' => $this->branch->ulid, 'label' => 'Barra'])->assertCreated()->json('data.ulid');
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/pos-accounts/{$cuenta}/orders", ['lines' => [['article_ulid' => $this->cerveza->ulid, 'quantity' => '2']]])->assertCreated();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('pide un export, lo genera y lo descarga', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/exports', ['format' => 'csv'])
        ->assertStatus(202)
        ->json('data.ulid');

    // La cola corrió inline: el export quedó listo, con su fila de datos.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/report-exports/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.row_count', 1);

    // Y se descarga, con el encabezado y la fila de la cerveza.
    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->get("/api/v1/report-exports/{$ulid}/download")
        ->assertOk();

    $contenido = $respuesta->streamedContent();
    expect($contenido)->toContain('Artículo')->toContain('Cerveza');

    // Y aparece en «mis exportaciones».
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/report-exports')
        ->assertOk()
        ->assertJsonPath('data.0.ulid', $ulid);
});

it('un formato inválido se rechaza', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/exports', ['format' => 'docx'])
        ->assertStatus(422);
});

it('un export no está disponible para otro usuario del mismo negocio', function () {
    $ulid = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson('/api/v1/reports/sales.by_article/exports', ['format' => 'csv'])
        ->assertStatus(202)
        ->json('data.ulid');

    // Otro negocio no lo ve (tenant scope): 404.
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );
    app(TenantContext::class)->forget();

    $this->actingAsSpa($otro['owner'], $otro['tenant']->id)
        ->getJson("/api/v1/report-exports/{$ulid}")
        ->assertNotFound();
});
