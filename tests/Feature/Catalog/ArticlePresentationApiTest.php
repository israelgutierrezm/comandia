<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * PRESENTACIONES DE COMPRA POR API (D22)
 *
 * Cuatro endpoints que no se habían llamado nunca, encontrados en la auditoría de cierre de la Iteración 2.
 *
 * Resuelven un problema muy concreto: el jitomate se compra por caja de 12 kg y se consume por gramo. Sin
 * presentaciones, quien captura costos divide a mano —$480 entre 12 000— y ése es el momento exacto en que
 * un costo entra con dos ceros de más y nadie lo nota hasta el corte.
 *
 * Lo que se cuida aquí es la **inmutabilidad de la cantidad** y la **unicidad de la presentación por
 * omisión**: la primera es el divisor con el que se calcularon los costos ya capturados, y la segunda
 * decide qué sale preseleccionado al capturar un costo.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda de Compras',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Pilar',
        ownerPaternalSurname: 'Escobar',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->set($this->tenant->id);

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate saladet',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('crea una presentación con su cantidad en unidad base', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja de 12 kg',
            'quantity_in_base_unit' => '12000',
            'barcode' => '7501234567890',
            'is_default' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Caja de 12 kg')
        // Como CADENA y con la escala de la columna: es un DECIMAL(12,4) y es el DIVISOR del costo
        // unitario. Convertirlo a número en el JSON metería error en la única operación donde importa.
        ->assertJsonPath('data.quantity_in_base_unit', '12000.0000')
        ->assertJsonPath('data.is_default', true);
});

it('rechaza una cantidad de cero: sería un divisor imposible', function () {
    // Hay un CHECK en la tabla que lo garantiza, y la validación lo dice antes con un mensaje legible. Un
    // cero aquí produciría un costo infinito, o una división por cero al capturar.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja vacía',
            'quantity_in_base_unit' => '0',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['quantity_in_base_unit']]);
});

it('al editar NO se puede cambiar la cantidad', function () {
    $creada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja de 12 kg',
            'quantity_in_base_unit' => '12000',
        ])
        ->assertCreated()
        ->json('data.ulid');

    // Se RECHAZA en lugar de ignorarse: quien la manda cree que va a cambiar algo, y un cambio que se
    // acepta y no ocurre es peor que un rechazo. Cambiarla no corregiría un costo pasado —reinterpretaría
    // todos los que se calcularon dividiendo por ella— y si el proveedor cambió el tamaño de la caja, es
    // otra presentación.
    //
    // Hasta esta prueba, el `PATCH` reutilizaba el Form Request del alta y la cantidad SÍ se cambiaba.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/articles/{$this->jitomate->ulid}/presentations/{$creada}", [
            'name' => 'Caja grande',
            'quantity_in_base_unit' => '24000',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['quantity_in_base_unit']]);

    // Y el resto sí se edita.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/articles/{$this->jitomate->ulid}/presentations/{$creada}", [
            'name' => 'Caja grande',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Caja grande')
        ->assertJsonPath('data.quantity_in_base_unit', '12000.0000');
});

it('sólo una presentación puede ser la de omisión', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja de 12 kg', 'quantity_in_base_unit' => '12000', 'is_default' => true,
        ])
        ->assertCreated();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Costal de 25 kg', 'quantity_in_base_unit' => '25000', 'is_default' => true,
        ])
        ->assertCreated();

    // La segunda desplaza a la primera en lugar de coexistir: con dos «por omisión», qué sale
    // preseleccionado al capturar un costo dependería del orden de la consulta.
    $listado = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/presentations")
        ->assertOk()
        ->json('data');

    $porOmision = collect($listado)->where('is_default', true)->pluck('name')->all();

    expect($porOmision)->toBe(['Costal de 25 kg']);
});

it('archiva una presentación sin borrarla', function () {
    $creada = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja de 12 kg', 'quantity_in_base_unit' => '12000',
        ])
        ->json('data.ulid');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations/{$creada}/archive")
        ->assertOk();

    // Sigue existiendo: los costos capturados a través de ella la citan, y borrarla dejaría el histórico
    // apuntando a la nada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/presentations")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'inactive');
});

it('la presentación de un artículo ajeno no se puede tocar desde otro artículo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();
    $queso = Article::create(['name' => 'Queso', 'base_unit_id' => $gramo->id, 'is_supply' => true]);
    $presentacion = $queso->purchasePresentations()->create([
        'name' => 'Pieza de 1 kg',
        'quantity_in_base_unit' => '1000.0000',
    ]);

    app(TenantContext::class)->forget();

    // La ruta anida la presentación dentro del artículo, y esa relación tiene que verificarse: sin ello,
    // conocer un ULID permitiría editar la presentación de cualquier otro artículo del negocio.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/articles/{$this->jitomate->ulid}/presentations/{$presentacion->ulid}", [
            'name' => 'Secuestrada',
        ])
        ->assertNotFound();
});

it('el almacenista no crea presentaciones: es administrar el catálogo', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $this->owner->syncRoles([$almacenista]);
    app(TenantContext::class)->forget();

    // Captura costos —tiene la factura en la mano— y no define la estructura del catálogo.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson("/api/v1/articles/{$this->jitomate->ulid}/presentations", [
            'name' => 'Caja', 'quantity_in_base_unit' => '12000',
        ])
        ->assertForbidden();
});
