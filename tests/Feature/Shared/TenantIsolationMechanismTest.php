<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Shared\Domain\Tenancy\Exceptions\TenantMismatchException;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Models\ScopedFixture;

/**
 * Pruebas del MECANISMO de aislamiento, contra MySQL real y sobre una tabla de
 * apoyo (D60).
 *
 * Estas pruebas son distintas de los tests de aislamiento por módulo que exige la
 * definition of done: aquéllos verifican que un módulo concreto no filtre; éstos
 * verifican que la herramienta con la que todos los módulos se protegen funciona.
 * Si esto se rompe, se rompe el aislamiento de todo el producto a la vez.
 */
beforeEach(function () {
    $this->context = app(TenantContext::class);
    $this->context->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('el contexto de tenant es un singleton', function () {
    // Carga la regla entera: si se resolviera una instancia nueva por inyección,
    // el global scope leería un contexto vacío y el aislamiento dependería del
    // azar de quién resolvió primero (ARQUITECTURA_MAESTRA §3: un request, un tenant).
    expect(app(TenantContext::class))->toBe(app(TenantContext::class));
});

it('una lectura sin contexto de tenant falla ruidosamente', function () {
    // La regla que más importa de todo el kernel: sin contexto NO se devuelve
    // vacío, se lanza excepción. Un resultado vacío disfrazaría el error de
    // programación como un dato legítimo y llegaría a producción.
    expect(fn () => ScopedFixture::query()->get())
        ->toThrow(MissingTenantContextException::class);
});

it('una escritura sin contexto de tenant falla ruidosamente', function () {
    expect(fn () => ScopedFixture::create(['name' => 'sin contexto']))
        ->toThrow(MissingTenantContextException::class);
});

it('rellena el tenant_id al crear sin que nadie lo pase', function () {
    $this->context->set(7);

    $row = ScopedFixture::create(['name' => 'Mesa 1']);

    expect($row->tenantId())->toBe(7);

    // Y de verdad quedó en la base, no sólo en el objeto.
    expect(DB::table('scoped_fixtures')->where('id', $row->id)->value('tenant_id'))
        ->toBe(7);
});

it('no permite pasar un tenant_id distinto al del contexto', function () {
    $this->context->set(7);

    // `tenant_id` está en $guarded de DomainModel, así que la asignación masiva no
    // puede colarlo. Con preventSilentlyDiscardingAttributes activo, intentarlo es
    // un error visible en lugar de un descarte silencioso.
    expect(fn () => ScopedFixture::create(['name' => 'Mesa 2', 'tenant_id' => 9]))
        ->toThrow(MassAssignmentException::class);
});

it('el global scope oculta por completo las filas de otro tenant', function () {
    $this->context->runFor(1, fn () => ScopedFixture::create(['name' => 'del tenant 1']));
    $this->context->runFor(2, fn () => ScopedFixture::create(['name' => 'del tenant 2']));

    $this->context->set(1);

    expect(ScopedFixture::query()->count())->toBe(1);
    expect(ScopedFixture::query()->first()->name)->toBe('del tenant 1');

    // Ni siquiera buscándola por su id primario: es el caso del `find()` ajeno,
    // el más fácil de escribir por descuido.
    $ajena = $this->context->runFor(2, fn () => ScopedFixture::query()->first());

    expect(ScopedFixture::query()->find($ajena->id))->toBeNull();
});

it('bloquea mover una fila de un tenant a otro', function () {
    $this->context->set(1);

    $row = ScopedFixture::create(['name' => 'no me muevas']);

    // El scope protege las lecturas. Sin este candado, un update podría trasladar
    // la fila al otro tenant y el scope ni se enteraría: la fila ya estaría del
    // otro lado.
    $row->setAttribute('tenant_id', 2);

    expect(fn () => $row->save())->toThrow(TenantMismatchException::class);

    expect(DB::table('scoped_fixtures')->where('id', $row->id)->value('tenant_id'))->toBe(1);
});

it('runFor restaura el contexto anterior incluso si el callback lanza', function () {
    $this->context->set(1);

    try {
        $this->context->runFor(2, function () {
            expect(app(TenantContext::class)->id())->toBe(2);

            throw new RuntimeException('job fallido');
        });
    } catch (RuntimeException) {
        // esperado
    }

    // Éste es el escenario de fuga: un job que falla a media cola dejando abierto
    // el contexto de otro tenant para el siguiente job del mismo worker.
    expect($this->context->id())->toBe(1);
});

it('runWithout deja el dominio sin contexto y luego lo restaura', function () {
    $this->context->set(5);

    $this->context->runWithout(function () {
        expect(app(TenantContext::class)->has())->toBeFalse();
    });

    expect($this->context->id())->toBe(5);
});

it('withoutTenantScope permite la consulta agregada del super admin', function () {
    $this->context->runFor(1, fn () => ScopedFixture::create(['name' => 'a']));
    $this->context->runFor(2, fn () => ScopedFixture::create(['name' => 'b']));

    $this->context->set(1);

    // Única salida autorizada, y su uso está restringido al módulo de super admin
    // por un test estructural aparte.
    expect(ScopedFixture::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('califica la columna con la tabla para no romper consultas con join', function () {
    $this->context->set(1);

    $sql = ScopedFixture::query()->toSql();

    // Sin qualifyColumn, un join entre dos tablas que ambas tienen `tenant_id`
    // produce "Column 'tenant_id' in where clause is ambiguous", y el momento de
    // descubrirlo no debe ser el primer reporte con join en producción.
    expect($sql)->toContain('`scoped_fixtures`.`tenant_id`');
});
