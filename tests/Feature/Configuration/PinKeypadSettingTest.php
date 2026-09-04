<?php

declare(strict_types=1);

use App\Modules\Configuration\Application\Settings;
use App\Modules\Configuration\Domain\Enums\SettingScope;
use App\Modules\Configuration\Domain\Enums\SettingType;
use App\Modules\Configuration\Domain\SettingCatalog;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

/**
 * El setting del teclado PIN en pantalla (`pos.onscreen_pin_keypad`, D20).
 *
 * Es sólo el modo de CAPTURA del PIN —el valor que se valida es el mismo—, así que no tiene reglas de negocio propias.
 * Lo que importa: que exista en el catálogo con el contrato correcto y que la cascada por sucursal —de donde el shell
 * saca el default que viaja al front— resuelva bien y aísle por negocio.
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

it('está en el catálogo como booleano por sucursal, apagado por omisión', function () {
    $definicion = SettingCatalog::get('pos.onscreen_pin_keypad');

    expect($definicion->type)->toBe(SettingType::Bool);
    expect($definicion->maxScope)->toBe(SettingScope::Branch);
    expect($definicion->default)->toBeFalse();
    expect($definicion->module)->toBe('Pos');
});

it('apagado si nadie lo configura', function () {
    expect($this->settings->forBranch('pos.onscreen_pin_keypad', $this->branch->id))->toBeFalse();
});

it('el override de sucursal enciende el teclado sólo en esa sucursal', function () {
    $otra = Branch::factory()->create();

    $this->settings->setForBranch('pos.onscreen_pin_keypad', $this->branch->id, true);

    expect($this->settings->forBranch('pos.onscreen_pin_keypad', $this->branch->id))->toBeTrue();
    expect($this->settings->forBranch('pos.onscreen_pin_keypad', $otra->id))->toBeFalse();
});

it('no se filtra entre negocios', function () {
    // Encendido en la sucursal del primer negocio.
    $this->settings->setForBranch('pos.onscreen_pin_keypad', $this->branch->id, true);

    // Otro negocio, con su propia sucursal: no ve el encendido del primero.
    $otroTenant = Tenant::factory()->create();
    app(TenantContext::class)->set($otroTenant->id);
    $sucursalDelOtro = Branch::factory()->create();

    expect($this->settings->forBranch('pos.onscreen_pin_keypad', $sucursalDelOtro->id))->toBeFalse();
});
