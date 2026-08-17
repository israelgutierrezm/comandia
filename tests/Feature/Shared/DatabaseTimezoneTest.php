<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * TODA marca de tiempo de la base está en UTC (ARQUITECTURA_MAESTRA §7).
 *
 * Este archivo existe por un defecto real que se encontró al implementar la consulta de la
 * bitácora: MySQL con `time_zone = SYSTEM` devolvía la hora local, así que las tablas que declaran
 * su `created_at` con `useCurrent()` —las inmutables— se fechaban seis horas antes que todo lo
 * que Laravel escribía desde PHP.
 *
 * El síntoma era demoledor y difícil de ver: en una investigación, la entrada de auditoría de un
 * descuento aparecería seis horas antes de la venta a la que se refiere. Y como la bitácora es
 * inmutable, los datos mal fechados no se corrigen.
 *
 * Estas pruebas fallan en cualquier máquina cuya zona horaria no sea UTC si la configuración de
 * la conexión se rompe, que es exactamente cuando hacen falta.
 */
it('la conexión declara UTC explícitamente', function () {
    expect(config('database.connections.mysql.timezone'))->toBe('+00:00');
});

it('la sesión de MySQL está en UTC, no en la del sistema', function () {
    $tz = DB::selectOne('SELECT @@session.time_zone AS tz')->tz;

    expect($tz)->toBe('+00:00');
});

it('NOW() de MySQL coincide con el reloj UTC de PHP', function () {
    $mysql = DB::selectOne('SELECT NOW() AS ahora')->ahora;

    // Un minuto de margen para no depender del cruce de segundo entre las dos lecturas. Si la
    // configuración se rompiera, la diferencia serían HORAS, no segundos.
    expect(abs(now()->diffInSeconds($mysql)))->toBeLessThan(60);
});

it('el created_at que genera la BASE coincide con el que genera PHP', function () {
    // Es la comprobación que de verdad importa: `audit_entries.created_at` lo pone MySQL con
    // `useCurrent()`, y el `updated_at` de una tabla normal lo pone Eloquent desde PHP. Si las dos
    // fuentes discrepan, la base tiene dos relojes.
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->set($tenant->id);

    $branch = Branch::factory()->create();
    $entrada = AuditEntry::create(['action' => AuditAction::LOGIN]);

    expect(abs($entrada->created_at->diffInSeconds($branch->created_at)))->toBeLessThan(60);

    app(TenantContext::class)->forget();
});

it('las dos tablas inmutables se fechan en UTC', function () {
    $tenant = Tenant::factory()->create();

    app(TenantContext::class)->set($tenant->id);

    $transicion = TenantStatusTransition::create([
        'from_status' => TenantStatus::PendingActivation,
        'to_status' => TenantStatus::Active,
    ]);

    $entrada = AuditEntry::create(['action' => AuditAction::TENANT_STATUS_CHANGED]);

    foreach ([$transicion, $entrada] as $registro) {
        expect(abs(now()->diffInSeconds($registro->created_at)))
            ->toBeLessThan(60, $registro::class.' no se fechó en UTC');
    }

    app(TenantContext::class)->forget();
});
