<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Configuration\Domain\Enums\SettingType;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * Configuración por API: la cascada expuesta (ARQUITECTURA_MAESTRA §5).
 */
beforeEach(function () {
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

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('lista TODAS las llaves del catálogo, no sólo las guardadas', function () {
    // Listar sólo lo guardado dejaría invisible todo lo que el tenant no ha tocado, que al
    // principio es todo.
    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/settings')
        ->assertOk()
        ->json('data');

    $llaves = array_column($datos, 'key');

    expect($llaves)->toContain('tax.vat_rate', 'pos.lock_items_on_bill_request', 'pricing.rounding_mode');
});

it('cada llave viaja con lo que el frontend necesita para pintar su control', function () {
    // El cliente no lleva una tabla de tipos ni de valores válidos: los recibe. Así agregar una
    // llave no exige tocar el frontend.
    $datos = collect($this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/settings')
        ->json('data'))
        ->keyBy('key');

    $redondeo = $datos['pricing.rounding_mode'];

    expect($redondeo)->toHaveKeys([
        'key', 'module', 'module_label', 'description', 'type', 'allowed_values', 'allowed_options',
        'max_scope', 'value', 'is_overridden', 'inherited_value', 'default_value',
    ]);

    expect($redondeo['type'])->toBe('enum');
    expect($redondeo['allowed_values'])->toBe(['none', 'integer', 'multiple_5', 'multiple_10']);
    expect($redondeo['max_scope'])->toBe('tenant');
    expect($redondeo['description'])->not->toBeEmpty();

    // El identificador del módulo es inglés porque es código; lo que se pinta es la etiqueta. La
    // pantalla mostraba «COSTING» y «CONFIGURATION» como encabezados de grupo.
    expect($redondeo['module'])->toBe('Costing');
    expect($redondeo['module_label'])->toBe('Costos y precios');
});

it('los valores de un enumerado viajan con etiqueta en español', function () {
    // La pantalla ofrecía `multiple_5`, `on_pickup` y `branch_default` tal cual. Las etiquetas viven
    // en el catálogo y no en el frontend porque la pantalla se autoconfigura desde la API: una tabla
    // de traducciones en Vue obligaría a tocar el frontend al agregar cada llave.
    $datos = collect($this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/settings')
        ->json('data'))
        ->keyBy('key');

    expect($datos['pricing.rounding_mode']['allowed_options'])->toBe([
        ['value' => 'none', 'label' => 'Sin redondeo'],
        ['value' => 'integer', 'label' => 'Al peso'],
        ['value' => 'multiple_5', 'label' => 'A múltiplos de $5'],
        ['value' => 'multiple_10', 'label' => 'A múltiplos de $10'],
    ]);

    // Una llave que no es enumerado no trae opciones: el control se elige por tipo.
    expect($datos['tax.vat_rate']['allowed_options'])->toBeNull();
});

it('todo enumerado con más de una opción real trae etiquetas', function () {
    // El candado. Un enumerado de una sola opción (`locale`, `currency`: v1 es México y MXN) no
    // ofrece elección y no necesita traducción; en cuanto hay dos, la persona elige y lo que lee
    // tiene que estar en español.
    $revisadas = 0;

    foreach (SettingCatalog::all() as $llave => $definicion) {
        if ($definicion->type !== SettingType::Enum || count($definicion->allowed ?? []) < 2) {
            continue;
        }

        $revisadas++;

        foreach ($definicion->allowed as $valor) {
            expect(array_key_exists($valor, $definicion->allowedLabels))
                ->toBeTrue("La llave {$llave} ofrece «{$valor}» sin etiqueta en español.");
        }
    }

    // Meta-verificación: sin esto el candado aprobaría en vacío el día que alguien cambie el
    // catálogo o el filtro y el bucle deje de recorrer nada.
    expect($revisadas)->toBeGreaterThanOrEqual(3);
});

it('distingue heredar de estar configurado', function () {
    // Si el default del sistema cambia en una versión futura, una llave que hereda sigue el nuevo
    // valor y una con override no. La UI necesita poder mostrar la diferencia.
    $antes = collect($this->actingAsSpa($this->owner, $this->tenant->id)
        ->getJson('/api/v1/settings')->json('data'))->keyBy('key');

    expect($antes['tax.vat_rate']['is_overridden'])->toBeFalse();
    expect((float) $antes['tax.vat_rate']['value'])->toBe(16.0);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/tax.vat_rate', ['value' => 8])
        ->assertOk()
        ->assertJsonPath('data.is_overridden', true)
        ->assertJsonPath('data.value', 8)
        // Y dice a qué valor volvería si se restaura.
        ->assertJsonPath('data.inherited_value', 16);
});

it('conserva el tipo declarado al leer y al escribir', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/pos.lock_items_on_bill_request', ['value' => false])
        ->assertOk()
        ->assertJsonPath('data.value', false);

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/security.pin_max_attempts', ['value' => 3])
        ->assertOk()
        ->assertJsonPath('data.value', 3);
});

it('acepta las formas de booleano que Laravel reconoce', function () {
    foreach ([false, 0, '0'] as $apagado) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->putJson('/api/v1/settings/pos.lock_items_on_bill_request', ['value' => $apagado])
            ->assertOk()
            ->assertJsonPath('data.value', false);
    }

    foreach ([true, 1, '1'] as $encendido) {
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->putJson('/api/v1/settings/pos.lock_items_on_bill_request', ['value' => $encendido])
            ->assertOk()
            ->assertJsonPath('data.value', true);
    }
});

it('RECHAZA la cadena "false" en lugar de interpretarla', function () {
    // Muchos lenguajes tratan cualquier cadena no vacía como verdadera: un cliente que mandara
    // "false" creyendo apagar un toggle podría encenderlo. Mejor un 422 explícito que una
    // ambigüedad silenciosa en un ajuste booleano.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/pos.lock_items_on_bill_request', ['value' => 'false'])
        ->assertStatus(422);
});

it('rechaza un valor del tipo equivocado', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/security.pin_max_attempts', ['value' => 'muchos'])
        ->assertStatus(422)
        ->assertJsonPath('errors.value.0', 'Esta configuración sólo admite un número entero.');
});

it('rechaza un valor fuera del conjunto y dice cuáles valen', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/pricing.rounding_mode', ['value' => 'multiple_7'])
        ->assertStatus(422)
        ->assertJsonPath('errors.value.0', 'Valor no permitido. Los válidos son: none, integer, multiple_5, multiple_10.');
});

it('una llave inexistente es 404, no 422', function () {
    // El recurso no existe; decir "dato inválido" sugeriría que la llave es correcta y el valor no.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/inventada.por.alguien', ['value' => 1])
        ->assertNotFound()
        ->assertJsonPath('type', 'not_found');
});

it('restaurar devuelve la llave a heredar', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/tax.vat_rate', ['value' => 8])->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson('/api/v1/settings/tax.vat_rate')
        ->assertOk()
        ->assertJsonPath('data.is_overridden', false)
        ->assertJsonPath('data.value', 16);
});

it('audita el cambio de configuración con antes y después', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/tax.vat_rate', ['value' => 8])->assertOk();

    app(TenantContext::class)->set($this->tenant->id);

    $entrada = AuditEntry::query()->where('action', AuditAction::SETTING_UPDATED)->firstOrFail();

    expect((float) $entrada->before['value'])->toBe(16.0);
    expect((float) $entrada->after['value'])->toBe(8.0);
    expect($entrada->after['key'])->toBe('tax.vat_rate');
});

// ---------------------------------------------------------------------------
// Nivel sucursal
// ---------------------------------------------------------------------------

it('la sucursal sólo lista las llaves que admiten override por sucursal', function () {
    // Mostrar `locale` aquí sería ofrecer un control que devuelve 422 al guardarlo.
    $llaves = array_column(
        $this->actingAsSpa($this->owner, $this->tenant->id)
            ->getJson("/api/v1/branches/{$this->branch->ulid}/settings")
            ->assertOk()
            ->json('data'),
        'key',
    );

    expect($llaves)->toContain('tax.vat_rate', 'pos.lock_items_on_bill_request');
    expect($llaves)->not->toContain('locale', 'pricing.rounding_mode');
});

it('el override de sucursal gana al de tenant', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/tax.vat_rate', ['value' => 8])->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/branches/{$this->branch->ulid}/settings/tax.vat_rate", ['value' => 16])
        ->assertOk()
        ->assertJsonPath('data.value', 16)
        // Lo heredado es el valor de tenant, no el default: es a donde volvería.
        ->assertJsonPath('data.inherited_value', 8);

    // Y el nivel tenant no se tocó: el override de sucursal es más específico, no sustituye al
    // de arriba. Se busca por llave y no por índice, que dependería del orden del catálogo.
    $porLlave = collect(
        $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/settings')->json('data')
    )->keyBy('key');

    expect((float) $porLlave['tax.vat_rate']['value'])->toBe(8.0);
});

it('rechaza override por sucursal de una llave que sólo llega a tenant', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/branches/{$this->branch->ulid}/settings/pricing.rounding_mode", ['value' => 'integer'])
        ->assertStatus(422);
});

it('no ofrece ajustes de un módulo activable no contratado', function () {
    // §4.2: no se ofrece configurar un módulo que no se contrató. Invariante genérico sobre lo que devuelve el
    // índice —sobrevive a que se agreguen o quiten llaves—: con un tenant recién dado de alta ningún módulo
    // activable está contratado, así que ninguno de sus ajustes puede aparecer.
    //
    // (Antes esto se probaba con `ecommerce.auto_accept_orders`; esa llave se quitó del catálogo en el punto 5
    // porque el control real vive en `stores.auto_accept_orders`. Hoy no queda ningún ajuste de módulo activable,
    // así que la comprobación es estructural: guarda la regla para cuando vuelva a haber uno.)
    $activables = collect((array) config('comandia.modules', []))
        ->filter(fn (array $m): bool => ($m['activatable'] ?? false) === true)
        ->keys()->all();

    $datos = $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/settings')->json('data');

    foreach ($datos as $ajuste) {
        expect($activables)->not->toContain(
            $ajuste['module'],
            "Apareció «{$ajuste['key']}» de un módulo activable no contratado.",
        );
    }
});

it('no ofrece enumerados de una sola opción (locale, currency: sin elección real)', function () {
    // Un select de una sola opción no cambia nada: `locale`/`currency` (México, MXN en v1 — D52) se quedan en el
    // catálogo —default y lecturas internas siguen— pero no se le ofrecen al usuario.
    $llaves = array_column(
        $this->actingAsSpa($this->owner, $this->tenant->id)->getJson('/api/v1/settings')->json('data'),
        'key',
    );

    expect($llaves)->not->toContain('locale', 'currency');
    expect(SettingCatalog::has('locale'))->toBeTrue('La llave sigue en el catálogo; sólo no se ofrece en la UI.');
});

it('la sucursal de otro negocio no se puede configurar', function () {
    $otro = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Luna',
        plainPassword: 'secreto-largo-123',
    );

    app(TenantContext::class)->forget();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson("/api/v1/branches/{$otro['branch']->ulid}/settings/tax.vat_rate", ['value' => 8])
        ->assertNotFound();
});

it('un mesero no configura nada', function () {
    $mesero = app(TenantContext::class)->runFor($this->tenant->id, function (): Role {
        $rol = Role::query()->where('name', RoleTemplates::WAITER)->firstOrFail();
        $this->owner->assignRole($rol);

        return $rol;
    });

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->getJson('/api/v1/settings')
        ->assertForbidden();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withHeader('X-Role', $mesero->ulid)
        ->putJson('/api/v1/settings/tax.vat_rate', ['value' => 8])
        ->assertForbidden();
});
