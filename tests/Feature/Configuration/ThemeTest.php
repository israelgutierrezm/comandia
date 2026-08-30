<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;

/**
 * Temas visuales (estilo Acadion).
 *
 * El negocio tiene un catálogo de temas (sembrado al provisionar), con uno por omisión. Cada persona puede elegir el
 * suyo y —si el tema lo permite— personalizar algunos colores. El shell entrega los tokens ya resueltos para que el
 * front sólo los inyecte; el frontend no conoce la paleta.
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

    // El tema del shell para el propietario. Closure ligado a `$this` para poder usar `actingAsSpa` (protegido).
    $this->shellTheme = fn (): array => $this->actingAsSpa($this->owner, $this->tenant->id)
        ->withoutVite()
        ->get('/admin')
        ->viewData('page')['props']['theme'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('siembra los seis temas al dar de alta el negocio', function () {
    $theme = ($this->shellTheme)();

    expect($theme['available'])->toHaveCount(6);
    expect(collect($theme['available'])->pluck('key')->all())
        ->toContain('oceano', 'indigo', 'medianoche', 'esmeralda', 'rosa_crema', 'alto_contraste');
});

it('sin elección, el shell entrega el tema por omisión del negocio (Océano)', function () {
    $theme = ($this->shellTheme)();

    expect($theme['key'])->toBe('oceano');
    expect($theme['tokens']['acento'])->toBe('#006A89');
    expect($theme['tokens']['barra_lateral'])->toBe('#00344D');
});

it('la persona elige su tema y el shell lo resuelve', function () {
    $medianoche = collect(($this->shellTheme)()['available'])->firstWhere('key', 'medianoche');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme', ['theme_ulid' => $medianoche['ulid']])
        ->assertOk()
        ->assertJsonPath('key', 'medianoche');

    expect(($this->shellTheme)()['key'])->toBe('medianoche');
});

it('rechaza un tema que no es del negocio', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme', ['theme_ulid' => str_repeat('A', 26)])
        ->assertStatus(422);
});

it('la pantalla de acceso no lleva tema (cae en los :root)', function () {
    $props = $this->withoutVite()->get('/login')->viewData('page')['props'];

    expect($props['theme']['key'])->toBeNull();
    expect($props['theme']['tokens'])->toBe([]);
});

it('la persona personaliza un color y el shell lo mezcla', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme/color', ['token' => 'acento', 'value' => '#123456'])
        ->assertOk()
        ->assertJsonPath('tokens.acento', '#123456');

    // Persiste en cada navegación.
    expect(($this->shellTheme)()['tokens']['acento'])->toBe('#123456');
});

it('restablecer borra los ajustes propios', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme/color', ['token' => 'acento', 'value' => '#123456'])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->deleteJson('/api/v1/preferences/theme/overrides')
        ->assertOk()
        ->assertJsonPath('tokens.acento', '#006A89');
});

it('un tema sin personalización (alto contraste) rechaza los ajustes de color', function () {
    $altoContraste = collect(($this->shellTheme)()['available'])->firstWhere('key', 'alto_contraste');

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme', ['theme_ulid' => $altoContraste['ulid']])
        ->assertOk();

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme/color', ['token' => 'acento', 'value' => '#123456'])
        ->assertStatus(422);
});

it('el color debe ser un hexadecimal', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme/color', ['token' => 'acento', 'value' => 'azul'])
        ->assertStatus(422);
});

it('sólo se personalizan los tokens de la lista blanca', function () {
    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->putJson('/api/v1/preferences/theme/color', ['token' => 'fondo', 'value' => '#123456'])
        ->assertStatus(422);
});

it('el propietario cambia el tema por omisión del negocio', function () {
    $ulid = collect(($this->shellTheme)()['available'])->firstWhere('key', 'esmeralda')['ulid'];

    $this->actingAsSpa($this->owner, $this->tenant->id)
        ->postJson("/api/v1/themes/{$ulid}/default")
        ->assertOk();

    // Un usuario que no ha elegido ve el nuevo default.
    expect(($this->shellTheme)()['key'])->toBe('esmeralda');
});
