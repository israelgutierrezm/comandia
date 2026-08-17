<?php

declare(strict_types=1);

use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Configuration\Infrastructure\Models\BranchSetting;
use App\Modules\Configuration\Infrastructure\Models\TenantSetting;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\MembershipBranchScope;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantScope;
use App\Modules\Shared\Infrastructure\Models\DocumentSequence;
use App\Modules\Tenancy\Infrastructure\Models\Subscription;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantLimit;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\Models\ScopedFixture;
use Tests\Fixtures\Models\UnscopedFixture;
use Tests\Fixtures\Scopes\ImpostorTenantScope;
use Tests\Support\DomainModelDiscovery;

/**
 * TEST ESTRUCTURAL DE SCOPES DE TENANT
 *
 * Exigido por ADR-002 y por ARQUITECTURA_MAESTRA §11. Es la red de seguridad
 * contra el único fallo verdaderamente catastrófico del producto: que los datos
 * de un negocio se vean desde otro.
 *
 * Este archivo debe permanecer verde en todo momento. Si falla, no se
 * "arregla el test": se le pone el scope al modelo o se agrega a la lista de
 * excepciones CON justificación escrita.
 *
 * La comparación es por FQCN exacto de App\Modules\Shared\Domain\Tenancy\TenantScope:
 * un `TenantScope` casero en otro namespace NO cuenta.
 */

/*
|--------------------------------------------------------------------------
| Excepciones justificadas
|--------------------------------------------------------------------------
|
| Un modelo entra aquí SÓLO si es global al SaaS por diseño y su tabla no
| contiene datos operativos de ningún tenant. Cada entrada lleva su razón.
|
| Son cuatro. Las tres primeras corresponden a las excepciones a la Regla A
| declaradas en §1 del diseño; la cuarta es de naturaleza distinta —la tabla SÍ
| lleva `tenant_id`, pero el modelo no puede llevar scope— y está justificada
| abajo. La quinta excepción a la Regla A, `role_has_permissions`, no aparece
| aquí porque no tiene modelo Eloquent: Spatie la maneja como pivote.
|
| Toda alta en esta lista es una decisión de arquitectura: si dudas, no la
| agregues.
|
*/
$allowlist = [
    // 1. La raíz del aislamiento. Su PK ES el tenant_id: acotarse a sí mismo no
    //    significa nada.
    Tenant::class,

    // 2. Identidad global del SaaS (ESPECIFICACIÓN_MAESTRA §4.1, capa 1): un correo
    //    es único en toda la plataforma y una persona puede pertenecer a N tenants
    //    independientes. La pertenencia —y por tanto el aislamiento— vive en
    //    `tenant_memberships`, no aquí.
    User::class,

    // 3. Catálogo cerrado del sistema, definido en un seeder versionado. El tenant
    //    combina permisos en roles; no inventa permisos (D10). No contiene dato de
    //    ningún tenant, así que no hay nada que aislar.
    Permission::class,

    // 4. Sanctum resuelve el token ANTES de que exista contexto —de hecho el token
    //    ES el origen del contexto—, así que un scope aquí sería una dependencia
    //    circular con excepción garantizada en cada petición autenticada por token.
    //    Su aislamiento no depende del scope sino de algo más fuerte: el token se
    //    encuentra por su hash, y el middleware revalida en cada petición que la
    //    membresía siga activa.
    PersonalAccessToken::class,
];

it('todo modelo de dominio declara el global scope de tenant', function () use ($allowlist) {
    $offenders = DomainModelDiscovery::withoutTenantScope($allowlist);

    expect($offenders)->toBe([], sprintf(
        "Estos modelos consultan la base de datos sin acotar por tenant (ADR-002, Regla A):\n  - %s\n\n".
        "Agrega el global scope de tenant al modelo —normalmente heredando de DomainModel— o, si es\n".
        'global al SaaS por diseño, inclúyelo en la lista de excepciones de este archivo con su justificación.',
        implode("\n  - ", $offenders),
    ));
});

it('el detector reconoce un modelo que sí declara el scope', function () {
    expect(DomainModelDiscovery::hasTenantScope(ScopedFixture::class))->toBeTrue();
});

it('el detector reprueba un modelo que no declara el scope', function () {
    // Autoverificación: si esto pasara a true, el test principal estaría verde
    // por estar ciego, no por estar el proyecto correcto.
    expect(DomainModelDiscovery::hasTenantScope(UnscopedFixture::class))->toBeFalse();
});

it('el detector exige el scope del kernel y no cualquier clase llamada TenantScope', function () {
    // El endurecimiento a FQCN es lo que impide que un scope casero con el mismo
    // nombre corto, en otro namespace, pase por el candado.
    expect(DomainModelDiscovery::TENANT_SCOPE)->toBe(TenantScope::class);

    $impostor = new class extends Model
    {
        protected $table = 'impostors';

        protected static function booted(): void
        {
            self::addGlobalScope(new ImpostorTenantScope);
        }
    };

    expect(DomainModelDiscovery::hasTenantScope($impostor::class))->toBeFalse();
});

it('el descubrimiento de modelos recorre app/ de verdad', function () {
    // Canario: si `User` desaparece de los resultados, el recorrido de archivos se
    // rompió y el test principal quedaría verde por estar ciego.
    expect(DomainModelDiscovery::all())->toContain(User::class);
});

it('la lista de excepciones tiene exactamente cuatro entradas', function () use ($allowlist) {
    // Candado sobre el candado. Las excepciones al aislamiento no deben crecer sin
    // que alguien lo note: si este test falla, alguien agregó una quinta y hace
    // falta decidir si está justificada —y registrarla en el diseño—.
    expect($allowlist)->toHaveCount(4);
});

it('descubre los modelos del kernel', function () {
    // Verifica que los modelos de la Iteración 1 existen y son alcanzables por el
    // detector. Sin esto, un módulo entero podría quedar fuera del recorrido —por un
    // namespace mal escrito, por ejemplo— y su falta de scope pasaría inadvertida.
    $descubiertos = DomainModelDiscovery::all();

    expect($descubiertos)->toContain(
        AuditEntry::class,
        BranchSetting::class,
        TenantSetting::class,
        EmployeeProfile::class,
        MembershipBranchScope::class,
        Role::class,
        TenantMembership::class,
        Branch::class,
        PreparationArea::class,
        Terminal::class,
        Warehouse::class,
        DocumentSequence::class,
        Subscription::class,
        TenantLimit::class,
        TenantModule::class,
        TenantStatusTransition::class,
    );
});
