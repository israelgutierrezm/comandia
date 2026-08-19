<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * HISTORIAL DE PRECIOS DE PROVEEDOR (D26, §6.2)
 *
 * §6.2 lo pide «para comparación y detección de subidas», y las dos cosas son la misma prueba: **con una sola fila por
 * proveedor, ninguna de las dos se puede contestar.** Comparar exige varios proveedores; detectar una subida exige dos
 * observaciones del mismo.
 *
 * De ahí las dos garantías que estas pruebas cuidan:
 *
 *   1. **Normalizar a unidad base.** «3 cajas de 12 kg a 480» y «el kilo a 42» tienen que poder compararse, y sin
 *      normalizar el proveedor que vende en cajas grandes saldría cuarenta veces más caro.
 *   2. **La variación se calcula sobre el precio ANTERIOR.** Subir de 10 a 15 es un 50 %, no un 33 %, y el error hace
 *      que las subidas parezcan menores de lo que son.
 */
beforeEach(function () {
    $alta = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda que compara',
        ownerEmail: 'duena@fonda.mx',
        ownerFirstName: 'Julia',
        ownerPaternalSurname: 'Ibarra',
        plainPassword: 'contrasena-larga-1',
    );

    $this->tenant = $alta['tenant'];
    $this->owner = $alta['owner'];

    app(TenantContext::class)->set($this->tenant->id);

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $this->jitomate = Article::create([
        'name' => 'Jitomate',
        'base_unit_id' => $gramo->id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    // «La caja de 12 kg»: 12 000 g en la unidad base del artículo.
    $this->caja = ArticlePurchasePresentation::create([
        'article_id' => $this->jitomate->id,
        'name' => 'Caja de 12 kg',
        'quantity_in_base_unit' => '12000',
    ]);

    $this->beto = Supplier::create([
        'code' => 'DON-BETO',
        'legal_name' => 'Distribuidora del Bajío',
        'trade_name' => 'Don Beto',
    ]);

    $this->central = Supplier::create([
        'code' => 'CENTRAL',
        'legal_name' => 'Central de Abastos',
    ]);

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

/** Captura una observación por HTTP. */
function observa(Supplier $supplier, array $cuerpo): array
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->postJson("/api/v1/suppliers/{$supplier->ulid}/prices", array_merge([
            'article_ulid' => test()->jitomate->ulid,
        ], $cuerpo))
        ->assertCreated()
        ->json('data');
}

/** La comparación de un artículo. */
function comparacion(): array
{
    return test()->actingAsSpa(test()->owner, test()->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk()
        ->json('data.suppliers');
}

// -------------------------------------------------------------- Normalización

it('NORMALIZA a unidad base: la caja de 12 kg a 480 son 0.04 el gramo', function () {
    $observacion = observa($this->beto, [
        'presentation_ulid' => $this->caja->ulid,
        'price' => '480',
        'source' => 'quote',
    ]);

    // Sin esto, comparar «480» con «0.042 el gramo» daría a Don Beto por once mil veces más caro.
    expect($observacion['unit_price'])->toBe('0.0400')
        // Y lo capturado se conserva, porque es lo primero que alguien pide cuando la comparación no le cuadra.
        ->and($observacion['observed_price'])->toBe('480.0000')
        ->and($observacion['observed_quantity'])->toBe('12000.0000')
        ->and($observacion['presentation']['name'])->toBe('Caja de 12 kg');
});

it('sin presentación, el precio ya viene por unidad base', function () {
    $observacion = observa($this->central, ['price' => '0.0420', 'source' => 'manual']);

    expect($observacion['unit_price'])->toBe('0.0420')
        // `null`: no hubo conversión que explicar.
        ->and($observacion['observed_price'])->toBeNull()
        ->and($observacion['presentation'])->toBeNull();
});

it('rechaza una presentación que no es del artículo', function () {
    app(TenantContext::class)->set($this->tenant->id);

    $otroArticulo = Article::create([
        'name' => 'Chile',
        'base_unit_id' => $this->jitomate->base_unit_id,
        'is_supply' => true,
        'is_inventoriable' => true,
    ]);

    $otraCaja = ArticlePurchasePresentation::create([
        'article_id' => $otroArticulo->id,
        'name' => 'Bolsa de 1 kg',
        'quantity_in_base_unit' => '1000',
    ]);

    app(TenantContext::class)->forget();

    // Mezclarlas normalizaría el precio con la cantidad equivocada, y el historial quedaría con un precio por unidad
    // que no corresponde a nada.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/suppliers/{$this->beto->ulid}/prices", [
            'article_ulid' => $this->jitomate->ulid,
            'presentation_ulid' => $otraCaja->ulid,
            'price' => '480',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['presentation_ulid']]);
});

// ----------------------------------------------------------- Inmutabilidad

it('el historial es INMUTABLE: no se edita ni se borra', function () {
    $ulid = observa($this->beto, ['price' => '0.0400'])['ulid'];

    app(TenantContext::class)->set($this->tenant->id);

    $observacion = SupplierPrice::query()->where('ulid', $ulid)->sole();

    // Si el precio se capturó mal, lo cierto es que hubo un error de captura ese día. Borrarlo hace que el historial
    // mienta sobre lo que se sabía entonces — el mismo trato que el historial de costos (§7).
    expect(fn () => $observacion->update(['unit_price' => '0.0500']))
        ->toThrow(ImmutableRecordException::class);

    $observacion->refresh();

    expect(fn () => $observacion->delete())->toThrow(ImmutableRecordException::class);

    app(TenantContext::class)->forget();

    // Y no hay endpoint: se corrige agregando otra observación.
    //
    // 404 y no 405: la URI de una observación individual no existe para NINGÚN método, así que no hay ruta con la que
    // el verbo pueda chocar. Un 405 diría «esta dirección existe, pero no con PATCH», y eso sería falso — invitaría a
    // buscar el verbo correcto para algo que no se puede hacer de ninguna forma.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->patchJson("/api/v1/suppliers/{$this->beto->ulid}/prices/{$ulid}", ['price' => '0.05'])
        ->assertStatus(404);
});

it('no admite un precio de cero', function () {
    // Un cero no es un precio bajo: es la ausencia de precio, y en la comparación saldría siempre como el más barato.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/suppliers/{$this->beto->ulid}/prices", [
            'article_ulid' => $this->jitomate->ulid,
            'price' => '0',
        ])
        ->assertStatus(422);
});

it('no admite capturar `receipt` a mano', function () {
    // Ése lo escribe el sistema al confirmar una recepción (paso 9). Capturarlo a mano dejaría marcar como «precio
    // pagado» algo que nunca se pagó, y la comparación perdería su distinción más útil: un hecho frente a una promesa.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/suppliers/{$this->beto->ulid}/prices", [
            'article_ulid' => $this->jitomate->ulid,
            'price' => '0.04',
            'source' => 'receipt',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['source']]);
});

it('no se le capturan precios a un proveedor dado de baja', function () {
    app(TenantContext::class)->runFor($this->tenant->id, fn () => $this->beto->update(['status' => 'inactive']));

    // Su historial sigue consultable —eso es el punto de darlo de baja en lugar de borrarlo— pero un precio nuevo de
    // alguien a quien ya no se le compra sólo puede ser un error de selección.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/suppliers/{$this->beto->ulid}/prices", [
            'article_ulid' => $this->jitomate->ulid,
            'price' => '0.04',
        ])
        ->assertStatus(422)
        ->assertJsonPath('title', fn (string $t): bool => str_contains($t, 'dado de baja'));
});

// -------------------------------------------------------------- Comparación

it('COMPARA proveedores del más barato al más caro', function () {
    observa($this->beto, ['presentation_ulid' => $this->caja->ulid, 'price' => '480', 'source' => 'quote']);
    observa($this->central, ['price' => '0.0350', 'source' => 'manual']);

    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk()
        ->json('data.suppliers');

    // La Central a 0.035 es más barata que Don Beto a 0.040. Es la comparación que exige normalizar: uno cotizó en
    // cajas y el otro por gramo.
    expect($filas)->toHaveCount(2)
        ->and($filas[0]['supplier']['code'])->toBe('CENTRAL')
        ->and($filas[0]['latest']['unit_price'])->toBe('0.0350')
        ->and($filas[1]['supplier']['code'])->toBe('DON-BETO')
        // Una sola observación no es una tendencia: `null` y no «0 %», que afirmaría que el precio se mantuvo.
        ->and($filas[0]['change'])->toBeNull();
});

it('DETECTA la subida, y el porcentaje va sobre el precio ANTERIOR', function () {
    observa($this->beto, ['price' => '0.0400', 'observed_at' => now()->subMonth()->toDateString()]);
    observa($this->beto, ['price' => '0.0600', 'observed_at' => now()->toDateString()]);

    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk()
        ->json('data.suppliers');

    // De 0.04 a 0.06 es un 50 % de subida, no un 33 %. Dividir por el precio nuevo es el error que hace que las
    // subidas parezcan menores de lo que son, y es la razón por la que esto se calcula en el servidor.
    expect($filas[0]['latest']['unit_price'])->toBe('0.0600')
        ->and($filas[0]['previous']['unit_price'])->toBe('0.0400')
        ->and($filas[0]['change']['amount'])->toBe('0.0200')
        ->and($filas[0]['change']['percent'])->toBe('50.00')
        ->and($filas[0]['change']['direction'])->toBe('up')
        ->and($filas[0]['observations'])->toBe(2);
});

it('detecta la bajada igual', function () {
    observa($this->beto, ['price' => '0.0500', 'observed_at' => now()->subMonth()->toDateString()]);
    observa($this->beto, ['price' => '0.0400', 'observed_at' => now()->toDateString()]);

    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk()
        ->json('data.suppliers');

    expect($filas[0]['change']['direction'])->toBe('down')
        ->and($filas[0]['change']['percent'])->toBe('-20.00');
});

it('NO mezcla monedas: cada una es su propia comparación', function () {
    observa($this->beto, ['price' => '0.0400', 'currency' => 'MXN']);
    observa($this->beto, ['price' => '0.0025', 'currency' => 'USD']);

    $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk()
        ->json('data.suppliers');

    // DOS renglones del mismo proveedor, uno por moneda. No hay tipo de cambio en el sistema y no se va a inventar
    // uno: mezclarlas daría una «bajada del 94 %» que sólo es un cambio de divisa.
    expect($filas)->toHaveCount(2);

    $porMoneda = collect($filas)->keyBy('currency');

    expect($porMoneda['MXN']['latest']['unit_price'])->toBe('0.0400')
        ->and($porMoneda['USD']['latest']['unit_price'])->toBe('0.0025')
        // Y ninguna tiene variación: cada una tiene una sola observación en su moneda.
        ->and($porMoneda['MXN']['change'])->toBeNull()
        ->and($porMoneda['USD']['change'])->toBeNull();
});

it('varias observaciones del mismo día tienen un orden estable', function () {
    // Una factura y una cotización llegan el mismo martes. Sin desempate por llave, «el precio más reciente» lo
    // decidiría MySQL y la comparación cambiaría entre consultas idénticas (D182).
    $hoy = now()->toDateString();

    observa($this->beto, ['price' => '0.0400', 'observed_at' => $hoy]);
    observa($this->beto, ['price' => '0.0450', 'observed_at' => $hoy]);

    foreach (range(1, 3) as $ignored) {
        $filas = $this->actingAsSpa($this->owner, $this->tenant->id)
            ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
            ->assertOk()
            ->json('data.suppliers');

        // La última capturada gana, siempre la misma.
        expect($filas[0]['latest']['unit_price'])->toBe('0.0450');
    }
});

it('distingue un precio PAGADO de una cotización', function () {
    $observacion = observa($this->beto, ['price' => '0.0400', 'source' => 'quote']);

    // Una cotización es una promesa; una recepción es un hecho. Sin la distinción no habría forma de saber si el
    // precio con el que se está negociando alguna vez se cobró de verdad.
    expect($observacion['source'])->toBe('quote')
        ->and($observacion['source_label'])->toBe('Cotización')
        ->and($observacion['is_confirmed_purchase'])->toBeFalse();
});

it('publica el catálogo de orígenes, diciendo cuáles se pueden capturar', function () {
    $origenes = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/supplier-price-sources')
        ->assertOk()
        ->json('data');

    // Con las etiquetas hechas y con la marca de qué puede capturar una persona: sin decirlo, el cliente ofrecería
    // «Recepción» y recibiría un 422 (la lección de D139).
    expect($origenes)->toHaveCount(3);

    $porValor = collect($origenes)->keyBy('value');

    expect($porValor['receipt']['capturable_by_hand'])->toBeFalse()
        ->and($porValor['quote']['capturable_by_hand'])->toBeTrue()
        ->and($porValor['manual']['label'])->toBe('Captura manual');
});

// ------------------------------------------------------ Historial por proveedor

it('lista el historial de un proveedor, lo más reciente primero', function () {
    observa($this->beto, ['price' => '0.0400', 'observed_at' => now()->subMonth()->toDateString()]);
    observa($this->beto, ['price' => '0.0600', 'observed_at' => now()->toDateString()]);
    observa($this->central, ['price' => '0.0350']);

    $respuesta = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/suppliers/{$this->beto->ulid}/prices")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($respuesta->json('data.0.unit_price'))->toBe('0.0600');

    // Filtrable por artículo y por origen: es la ficha de la negociación.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/suppliers/{$this->beto->ulid}/prices?article={$this->jitomate->ulid}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/suppliers/{$this->beto->ulid}/prices?source=receipt")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // Sin búsqueda de texto: se rechaza en lugar de ignorarse (D182).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson("/api/v1/suppliers/{$this->beto->ulid}/prices?search=jitomate")
        ->assertStatus(422);
});

// --------------------------------------------------------------- Autorización

it('el almacenista VE precios de proveedor y no los captura', function () {
    observa($this->beto, ['price' => '0.0400']);

    app(TenantContext::class)->set($this->tenant->id);
    $almacenista = Role::query()->where('name', RoleTemplates::WAREHOUSE_KEEPER)->firstOrFail();
    $this->owner->syncRoles([$almacenista]);
    app(TenantContext::class)->forget();

    // Ve: recibe la mercancía con la factura en la mano y necesita comparar lo que le están cobrando (D161).
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertOk();

    // No captura: registrar una cotización es tomar una posición sobre a quién comprarle, y eso es de quien negocia.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $almacenista->ulid)
        ->postJson("/api/v1/suppliers/{$this->beto->ulid}/prices", [
            'article_ulid' => $this->jitomate->ulid,
            'price' => '0.05',
        ])
        ->assertForbidden();
});

it('el cajero no ve precios de proveedor', function () {
    app(TenantContext::class)->set($this->tenant->id);
    $cajero = Role::query()->where('name', RoleTemplates::CASHIER)->firstOrFail();
    $this->owner->syncRoles([$cajero]);
    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $cajero->ulid)
        ->getJson("/api/v1/articles/{$this->jitomate->ulid}/supplier-prices")
        ->assertForbidden();
});
