<?php

declare(strict_types=1);

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Shared\Domain\Support\Exceptions\ImmutableRecordException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Inmutabilidad de la bitácora (§6.7, ARQUITECTURA_MAESTRA §7).
 *
 * Se prueban las TRES vías de escritura destructiva por separado, porque cerrar
 * sólo la obvia deja la puerta abierta:
 *
 *   1. `$model->save()` sobre un registro existente → eventos de modelo.
 *   2. `$model->update()` / `->delete()` → métodos sobrescritos.
 *   3. `Model::query()->update()` / `->delete()` → el query builder, que **no
 *      dispara eventos** y sería la puerta más ancha si sólo se escucharan eventos.
 *
 * La tercera es la que importa de verdad: una consulta en masa es exactamente la
 * forma en que alguien "arreglaría" una bitácora que no le cuadra.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->entry = AuditEntry::create([
        'action' => 'auth.login',
        'before' => null,
        'after' => ['email' => 'ana@ejemplo.mx'],
        'ip_address' => '127.0.0.1',
    ]);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('permite insertar: es append-only, no read-only', function () {
    expect($this->entry->exists)->toBeTrue();
    expect($this->entry->ulid)->toHaveLength(26);
    expect($this->entry->after)->toBe(['email' => 'ana@ejemplo.mx']);
});

it('rechaza update() del modelo', function () {
    expect(fn () => $this->entry->update(['action' => 'auth.logout']))
        ->toThrow(ImmutableRecordException::class);
});

it('rechaza save() sobre un registro existente', function () {
    $this->entry->action = 'auth.logout';

    expect(fn () => $this->entry->save())->toThrow(ImmutableRecordException::class);
});

it('rechaza delete() del modelo', function () {
    expect(fn () => $this->entry->delete())->toThrow(ImmutableRecordException::class);
});

it('rechaza el UPDATE en masa del query builder', function () {
    // Los eventos de modelo NO cubren este camino. Sin el ImmutableBuilder, esta
    // línea reescribiría la bitácora completa del tenant sin que nada la detuviera.
    expect(fn () => AuditEntry::query()->update(['action' => 'auth.logout']))
        ->toThrow(ImmutableRecordException::class);
});

it('rechaza el DELETE en masa del query builder', function () {
    expect(fn () => AuditEntry::query()->delete())
        ->toThrow(ImmutableRecordException::class);
});

it('rechaza increment y decrement', function () {
    expect(fn () => AuditEntry::query()->increment('auditable_id'))
        ->toThrow(ImmutableRecordException::class);

    expect(fn () => AuditEntry::query()->decrement('auditable_id'))
        ->toThrow(ImmutableRecordException::class);
});

it('el registro sigue intacto en la base después de todos los intentos', function () {
    rescue(fn () => $this->entry->update(['action' => 'auth.logout']));
    rescue(fn () => AuditEntry::query()->update(['action' => 'auth.logout']));
    rescue(fn () => $this->entry->delete());

    $fila = DB::table('audit_entries')->where('id', $this->entry->id)->first();

    expect($fila)->not->toBeNull();
    expect($fila->action)->toBe('auth.login');
});

it('no gestiona updated_at porque no existe', function () {
    // Una tabla append-only no tiene fecha de modificación. Si el modelo intentara
    // escribirla, cada inserción fallaría por columna inexistente.
    expect($this->entry->usesTimestamps())->toBeFalse();
});

it('distingue al ejecutor del autorizador', function () {
    // La distinción que hace posible el reporte de robo hormiga (§9): sin dos
    // columnas, "el gerente autorizó que el mesero aplicara el descuento" y "el
    // gerente aplicó el descuento" serían el mismo registro.
    $entrada = AuditEntry::create([
        'action' => 'pos.discount_applied',
        'actor_membership_id' => null,
        'authorized_by_membership_id' => null,
    ]);

    expect($entrada->wasAuthorizedByAnother())->toBeFalse();
});
