<?php

declare(strict_types=1);

use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\Exceptions\InvalidSettingValueException;
use App\Modules\Configuration\Domain\Exceptions\SettingScopeViolationException;
use App\Modules\Configuration\Domain\Exceptions\UnknownSettingKeyException;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * Cascada de configuración: sistema → tenant → sucursal
 * (ARQUITECTURA_MAESTRA §5).
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create();
    $this->settings = app(Settings::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('sin configurar nada devuelve el default del catálogo', function () {
    // "El tenant que no configura nada obtiene un restaurante funcional" (§1). Los
    // defaults viven en código, así que no hace falta sembrar filas al dar de alta.
    expect($this->settings->get('pos.blind_precount'))->toBeTrue();
    expect($this->settings->get('tax.vat_rate'))->toBe(16.00);
    expect($this->settings->get('pricing.rounding_mode'))->toBe('none');
});

it('el override de tenant gana al default de sistema', function () {
    $this->settings->setForTenant('tax.vat_rate', 8.00);

    expect($this->settings->get('tax.vat_rate'))->toBe(8.00);
});

it('el override de sucursal gana al de tenant', function () {
    $this->settings->setForTenant('tax.vat_rate', 8.00);
    $this->settings->setForBranch('tax.vat_rate', $this->branch->id, 16.00);

    expect($this->settings->get('tax.vat_rate'))->toBe(8.00);
    expect($this->settings->forBranch('tax.vat_rate', $this->branch->id))->toBe(16.00);
});

it('una sucursal sin override hereda del tenant', function () {
    $this->settings->setForTenant('pos.blind_precount', false);

    expect($this->settings->forBranch('pos.blind_precount', $this->branch->id))->toBeFalse();
});

it('conserva el tipo declarado y no lo devuelve como texto', function () {
    // El valor se guarda en una columna de texto (D79). Sin el tipado del catálogo, un
    // `false` guardado como "0" se leería como cadena no vacía —y por tanto verdadera—,
    // y el precorte ciego quedaría activo creyendo lo contrario.
    $this->settings->setForTenant('pos.blind_precount', false);
    $this->settings->setForTenant('security.pin_max_attempts', 3);

    expect($this->settings->get('pos.blind_precount'))->toBeFalse();
    expect($this->settings->get('security.pin_max_attempts'))->toBe(3);
    expect($this->settings->get('security.pin_max_attempts'))->toBeInt();
});

it('rechaza una llave que no está en el catálogo', function () {
    // Prohibido inventar llaves desde el cliente (§5). Permitirlo crearía una fila que
    // nadie lee, y el usuario creería haber configurado algo que el sistema ignora.
    expect(fn () => $this->settings->get('inventada.por.alguien'))
        ->toThrow(UnknownSettingKeyException::class);

    expect(fn () => $this->settings->setForTenant('inventada.por.alguien', 1))
        ->toThrow(UnknownSettingKeyException::class);
});

it('rechaza override de sucursal en una llave que sólo llega a tenant', function () {
    // `locale` por sucursal no tiene sentido: una sucursal hablando otro idioma que el
    // resto del tenant sería un accidente, no una configuración.
    expect(fn () => $this->settings->setForBranch('locale', $this->branch->id, 'es_MX'))
        ->toThrow(SettingScopeViolationException::class);
});

it('rechaza un valor del tipo equivocado', function () {
    expect(fn () => $this->settings->setForTenant('pos.blind_precount', 'sí'))
        ->toThrow(InvalidSettingValueException::class);

    expect(fn () => $this->settings->setForTenant('security.pin_max_attempts', 'muchos'))
        ->toThrow(InvalidSettingValueException::class);
});

it('rechaza un valor fuera del conjunto permitido', function () {
    expect(fn () => $this->settings->setForTenant('pricing.rounding_mode', 'multiple_7'))
        ->toThrow(InvalidSettingValueException::class);
});

it('invalida la cache al escribir', function () {
    expect($this->settings->get('tax.vat_rate'))->toBe(16.00);

    // Sin invalidación, esta lectura devolvería el valor cacheado y el usuario vería su
    // cambio ignorado hasta que expirara la cache.
    $this->settings->setForTenant('tax.vat_rate', 8.00);

    expect($this->settings->get('tax.vat_rate'))->toBe(8.00);
});

it('distingue heredar de configurar con el mismo valor', function () {
    // La UI lo necesita: si el default del sistema cambia en una versión futura, una
    // llave que heredaba sigue el nuevo valor y una con override explícito no.
    expect($this->settings->hasTenantOverride('tax.vat_rate'))->toBeFalse();

    $this->settings->setForTenant('tax.vat_rate', 16.00);

    expect($this->settings->hasTenantOverride('tax.vat_rate'))->toBeTrue();
    expect($this->settings->get('tax.vat_rate'))->toBe(16.00);
});

it('al quitar el override vuelve a heredar', function () {
    $this->settings->setForTenant('tax.vat_rate', 8.00);
    $this->settings->resetForTenant('tax.vat_rate');

    expect($this->settings->hasTenantOverride('tax.vat_rate'))->toBeFalse();
    expect($this->settings->get('tax.vat_rate'))->toBe(16.00);
});

it('la configuración de otro tenant es invisible', function () {
    $this->settings->setForTenant('tax.vat_rate', 8.00);

    $otro = Tenant::factory()->create();

    app(TenantContext::class)->runFor($otro->id, function () {
        // Mismo servicio, otro tenant: el default, no el 8% del vecino.
        expect(app(Settings::class)->get('tax.vat_rate'))->toBe(16.00);
    });

    expect($this->settings->get('tax.vat_rate'))->toBe(8.00);
});

it('resuelve todas las llaves de una sucursal de una sola vez', function () {
    // Es lo que el shell del frontend necesita para no hacer una petición por toggle.
    $this->settings->setForBranch('pos.blind_precount', $this->branch->id, false);

    $todas = $this->settings->allForBranch($this->branch->id);

    expect($todas)->toHaveKey('pos.blind_precount', false);
    expect($todas)->toHaveKey('tax.vat_rate', 16.00);
    expect($todas)->toHaveKey('locale', 'es_MX');
});
