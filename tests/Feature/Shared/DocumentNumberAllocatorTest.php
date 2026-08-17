<?php

declare(strict_types=1);

use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Shared\Application\Folios\DocumentNumberAllocator;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Infrastructure\Models\DocumentSequence;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Foliación sin huecos (ARQUITECTURA_MAESTRA §7, D73).
 *
 * La propiedad crítica —y la que distingue esto de un AUTO_INCREMENT— se prueba en
 * "una transacción revertida no consume folio". Un autoincremental deja hueco en
 * cuanto algo se revierte; aquí el folio y el documento nacen o mueren juntos.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($this->tenant->id);

    $this->branch = Branch::factory()->create(['code' => 'CEN']);
    $this->allocator = app(DocumentNumberAllocator::class);
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('la primera petición devuelve el folio 1 y crea la secuencia', function () {
    $numero = DB::transaction(
        fn (): int => $this->allocator->next($this->branch->id, 'account', 'CEN')
    );

    expect($numero)->toBe(1);

    $secuencia = DocumentSequence::query()
        ->where('branch_id', $this->branch->id)
        ->where('document_type', 'account')
        ->where('series', 'CEN')
        ->first();

    expect($secuencia->next_number)->toBe(2);
});

it('entrega folios consecutivos sin huecos', function () {
    $folios = DB::transaction(function (): array {
        $resultado = [];

        for ($i = 0; $i < 10; $i++) {
            $resultado[] = $this->allocator->next($this->branch->id, 'account', 'CEN');
        }

        return $resultado;
    });

    expect($folios)->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
});

it('una transacción revertida NO consume folio', function () {
    DB::transaction(fn (): int => $this->allocator->next($this->branch->id, 'account', 'CEN'));

    // Documento que falla a medio camino: el folio tiene que volver a estar
    // disponible. Con AUTO_INCREMENT el 2 quedaría quemado para siempre.
    rescue(function (): void {
        DB::transaction(function (): void {
            $this->allocator->next($this->branch->id, 'account', 'CEN');

            throw new RuntimeException('el documento falló');
        });
    }, report: false);

    $siguiente = DB::transaction(
        fn (): int => $this->allocator->next($this->branch->id, 'account', 'CEN')
    );

    expect($siguiente)->toBe(2);
});

it('las secuencias son independientes por tipo de documento y por serie', function () {
    [$cuenta, $orden, $otraSerie] = DB::transaction(fn (): array => [
        $this->allocator->next($this->branch->id, 'account', 'CEN'),
        $this->allocator->next($this->branch->id, 'order', 'CEN'),
        $this->allocator->next($this->branch->id, 'account', 'ALT'),
    ]);

    expect($cuenta)->toBe(1);
    expect($orden)->toBe(1);
    expect($otraSerie)->toBe(1);
});

it('las secuencias son independientes por sucursal', function () {
    $otra = Branch::factory()->create(['code' => 'NTE']);

    [$primera, $segunda] = DB::transaction(fn (): array => [
        $this->allocator->next($this->branch->id, 'account', 'CEN'),
        $this->allocator->next($otra->id, 'account', 'NTE'),
    ]);

    expect($primera)->toBe(1);
    expect($segunda)->toBe(1);
});

it('las secuencias de otro tenant son invisibles', function () {
    DB::transaction(fn (): int => $this->allocator->next($this->branch->id, 'account', 'CEN'));

    $otroTenant = Tenant::factory()->create();

    app(TenantContext::class)->runFor($otroTenant->id, function (): void {
        $sucursal = Branch::factory()->create(['code' => 'CEN']);

        // Mismo código de sucursal, misma serie, mismo tipo: y aun así el folio
        // arranca en 1, porque la secuencia se define por (tenant, sucursal, tipo,
        // serie).
        $numero = DB::transaction(
            fn (): int => app(DocumentNumberAllocator::class)->next($sucursal->id, 'account', 'CEN')
        );

        expect($numero)->toBe(1);
        expect(DocumentSequence::query()->count())->toBe(1);
    });

    expect(DocumentSequence::query()->count())->toBe(1);
});

it('formatea el folio para presentación sin guardarlo así', function () {
    // En base se guarda el entero: ordenar y comparar textos con ceros a la izquierda
    // es una fuente inagotable de errores en reportes.
    expect($this->allocator->format('CEN', 42))->toBe('CEN-000042');
});
