<?php

declare(strict_types=1);

use App\Modules\Configuration\Domain\Enums\AccentPreset;
use App\Modules\Configuration\Domain\Enums\SidebarPreset;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * Apariencia del negocio (rediseño, Fase B).
 *
 * El acento de marca viaja en el shell de Inertia ya resuelto a hex, para que el frontend sólo lo inyecte en
 * `--color-acento`. La paleta es CERRADA: un valor fuera de ella se rechaza como cualquier enumerado del catálogo, así
 * nadie termina con un acento ilegible (texto blanco sobre un tono claro).
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

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('el shell comparte el acento por omisión cuando el negocio no ha elegido', function () {
    $props = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props['theme']['key'])->toBe('terracota');
    expect($props['theme']['accent'])->toBe('#c2410c');
});

it('el shell resuelve el hex del preset elegido', function () {
    // El frontend no conoce la paleta: recibe el hex ya resuelto por el backend. Así agregar o quitar un preset no
    // exige tocar Vue —salvo el mapa de previsualización de la propia pantalla de Apariencia—.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/appearance.accent', ['value' => 'esmeralda'])
        ->assertOk();

    $props = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props['theme']['key'])->toBe('esmeralda');
    expect($props['theme']['accent'])->toBe('#047857');
});

it('la paleta es cerrada: rechaza un color fuera de los presets', function () {
    // La apariencia no es un selector de color libre; es una lista blanca curada. Un hex arbitrario podría dejar el
    // acento ilegible, justo lo que la curaduría evita.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/appearance.accent', ['value' => '#ff00ff'])
        ->assertStatus(422);
});

it('el login siempre usa el acento por omisión, sin negocio en contexto', function () {
    // Fase C: la pantalla de acceso es independiente del tema del negocio (aún no hay negocio elegido). Sin esto,
    // `theme()` podría reventar al resolver el ajuste sin tenant en contexto.
    $props = $this->withoutVite()
        ->get('/login')
        ->viewData('page')['props'];

    expect($props['theme']['accent'])->toBe('#c2410c');
});

it('el refugio del acento: una clave desconocida cae en la terracota', function () {
    // Si una versión futura retira un preset, un negocio que lo tuviera guardado no debe quedarse sin acento:
    // `hexFor` cae en la terracota en lugar de devolver vacío.
    expect(AccentPreset::hexFor('un-preset-que-ya-no-existe'))->toBe('#c2410c');
    expect(AccentPreset::hexFor('esmeralda'))->toBe('#047857');
});

it('el shell comparte el color de barra por omisión cuando el negocio no ha elegido', function () {
    $props = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props['theme']['sidebar_key'])->toBe('piedra');
    expect($props['theme']['sidebar'])->toBe('#292524');
});

it('el shell resuelve el hex de la barra lateral elegida', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/appearance.sidebar', ['value' => 'noche'])
        ->assertOk();

    $props = $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props'];

    expect($props['theme']['sidebar_key'])->toBe('noche');
    expect($props['theme']['sidebar'])->toBe('#0f172a');
});

it('la paleta de la barra es cerrada: rechaza un color fuera de los presets', function () {
    // Todos los presets de barra son oscuros a propósito para que el texto claro del sidebar siga legible; un hex
    // libre podría dejar la navegación ilegible, justo lo que la curaduría evita.
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/settings/appearance.sidebar', ['value' => '#ffffff'])
        ->assertStatus(422);
});

it('el login siempre usa la barra por omisión, sin negocio en contexto', function () {
    $props = $this->withoutVite()
        ->get('/login')
        ->viewData('page')['props'];

    expect($props['theme']['sidebar'])->toBe('#292524');
});

it('el refugio de la barra: una clave desconocida cae en la piedra', function () {
    expect(SidebarPreset::hexFor('un-preset-que-ya-no-existe'))->toBe('#292524');
    expect(SidebarPreset::hexFor('noche'))->toBe('#0f172a');
});
