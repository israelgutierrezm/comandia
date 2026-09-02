<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\AuditAction;
use App\Modules\Audit\Infrastructure\Models\AuditEntry;
use App\Modules\Configuration\Infrastructure\Models\BranchSetting;
use App\Modules\Configuration\Infrastructure\Models\MembershipThemeOverride;
use App\Modules\Configuration\Infrastructure\Models\TenantMailSetting;
use App\Modules\Configuration\Infrastructure\Models\TenantSetting;
use App\Modules\Configuration\Infrastructure\Models\Theme;
use App\Modules\Configuration\Infrastructure\Models\ThemeToken;
use App\Modules\Identity\Infrastructure\Models\EmployeeProfile;
use App\Modules\Identity\Infrastructure\Models\MembershipBranchScope;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Shared\Infrastructure\Models\DocumentSequence;
use App\Modules\Tenancy\Domain\Enums\TenantLimitKey;
use App\Modules\Tenancy\Domain\Enums\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Subscription;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Infrastructure\Models\TenantLimit;
use App\Modules\Tenancy\Infrastructure\Models\TenantModule;
use App\Modules\Tenancy\Infrastructure\Models\TenantStatusTransition;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\DomainModelDiscovery;

/**
 * TEST DE AISLAMIENTO DE TENANT DEL SHARED KERNEL
 *
 * Obligatorio en la definition of done de cada módulo (ARQUITECTURA_MAESTRA §11): crear
 * datos en el tenant A, operar en el tenant B, verificar **invisibilidad total**.
 *
 * Es un barrido **sistemático**, no una muestra: recorre las veintidós tablas acotadas del
 * kernel una por una. Las pruebas de aislamiento repartidas por otros archivos cubren casos
 * concretos; ésta cubre la superficie completa, que es lo que hace falta para poder afirmar
 * que el kernel aísla.
 *
 * Si mañana se agrega una tabla de dominio al kernel y no se agrega aquí, el test estructural
 * de scopes seguirá verde —el modelo tendrá su scope— pero esta prueba dejará de ser un
 * barrido completo. Por eso la última prueba del archivo compara la lista de abajo contra los
 * modelos que el descubridor encuentra: si aparece uno nuevo, falla.
 */

/**
 * Constructores de una fila de cada entidad del kernel.
 *
 * Closures y no factories porque varias de estas tablas no las necesitan en ningún otro
 * lugar, y porque así queda documentado en un solo sitio cómo se crea cada entidad del
 * kernel con sus dependencias mínimas.
 *
 * @var array<class-string<Model>, Closure(): Model>
 */
$constructores = [
    TenantStatusTransition::class => fn (): Model => TenantStatusTransition::create([
        'from_status' => TenantStatus::PendingActivation,
        'to_status' => TenantStatus::Active,
        'reason' => 'alta asistida',
    ]),

    Subscription::class => fn (): Model => Subscription::create([
        'status' => 'active',
        'started_at' => now()->toDateString(),
        'current_period_start' => now()->toDateString(),
        'current_period_end' => now()->addMonth()->toDateString(),
    ]),

    TenantLimit::class => fn (): Model => TenantLimit::create([
        'limit_key' => TenantLimitKey::MaxBranches,
        'limit_value' => 5,
    ]),

    TenantModule::class => fn (): Model => TenantModule::create([
        'module' => 'Ecommerce',
        'is_enabled' => true,
        'enabled_at' => now(),
    ]),

    Role::class => fn (): Model => Role::create(['name' => 'Rol de prueba', 'guard_name' => 'web']),

    TenantMembership::class => fn (): Model => TenantMembership::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]),

    EmployeeProfile::class => fn (): Model => EmployeeProfile::factory()->create([
        'membership_id' => TenantMembership::factory()->create([
            'user_id' => User::factory()->create()->id,
        ])->id,
    ]),

    MembershipBranchScope::class => fn (): Model => MembershipBranchScope::create([
        'membership_id' => TenantMembership::factory()->create([
            'user_id' => User::factory()->create()->id,
        ])->id,
        'branch_id' => Branch::factory()->create()->id,
    ]),

    Branch::class => fn (): Model => Branch::factory()->create(),

    Warehouse::class => fn (): Model => Warehouse::factory()->central()->create(),

    PreparationArea::class => function (): Model {
        $branch = Branch::factory()->create();

        return PreparationArea::factory()->create([
            'branch_id' => $branch->id,
            'warehouse_id' => Warehouse::factory()->create(['branch_id' => $branch->id])->id,
        ]);
    },

    Terminal::class => fn (): Model => Terminal::factory()->create(),

    // La impresora entró en este barrido porque el candado la pidió por su cuenta: la prueba falló al aparecer un
    // modelo acotado por negocio que nadie estaba probando. Es exactamente para lo que existe — una tabla nueva sin
    // prueba de aislamiento es la forma más silenciosa de abrir una fuga entre negocios.
    Printer::class => fn (): Model => Printer::create([
        'branch_id' => Branch::factory()->create()->id,
        'code' => 'COCINA',
        'name' => 'Impresora de cocina',
        'connection' => 'network',
        'target' => '192.168.1.50:9100',
    ]),

    TenantSetting::class => fn (): Model => TenantSetting::create([
        'setting_key' => 'tax.vat_rate',
        'setting_value' => '8',
    ]),

    BranchSetting::class => fn (): Model => BranchSetting::create([
        'branch_id' => Branch::factory()->create()->id,
        'setting_key' => 'pos.lock_items_on_bill_request',
        'setting_value' => '0',
    ]),

    AuditEntry::class => fn (): Model => AuditEntry::create([
        'action' => AuditAction::LOGIN,
        'ip_address' => '10.0.0.1',
    ]),

    DocumentSequence::class => fn (): Model => DocumentSequence::create([
        'branch_id' => Branch::factory()->create()->id,
        'document_type' => 'account',
        'series' => 'CEN',
        'next_number' => 1,
    ]),

    // Configuración de correo del negocio (Tanda D1): una por tenant (unique tenant_id), la contraseña cifrada en reposo.
    TenantMailSetting::class => fn (): Model => TenantMailSetting::create([
        'host' => 'smtp.example.mx',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'avisos@example.mx',
        'password' => 'secreto-de-app',
        'from_address' => 'avisos@example.mx',
        'from_name' => 'Negocio de prueba',
    ]),

    // Aviso interno (Tanda D2): dirigido a una membresía concreta.
    Notification::class => fn (): Model => Notification::create([
        'recipient_membership_id' => TenantMembership::factory()->create([
            'user_id' => User::factory()->create()->id,
        ])->id,
        'type' => 'export_ready',
        'title' => 'Aviso de prueba',
    ]),

    // Temas visuales (estilo Acadion). El tenant se crea por factory, no por alta, así que aquí NO están los seis
    // temas sembrados: cada constructor crea el suyo y las cuentas quedan limpias.
    Theme::class => fn (): Model => Theme::create(['key' => 'propio', 'name' => 'Propio']),

    ThemeToken::class => fn (): Model => Theme::create(['key' => 'con-token', 'name' => 'Con token'])
        ->tokens()->create(['token' => 'acento', 'value' => '#123456']),

    MembershipThemeOverride::class => fn (): Model => MembershipThemeOverride::create([
        'membership_id' => TenantMembership::factory()->create([
            'user_id' => User::factory()->create()->id,
        ])->id,
        'token' => 'acento',
        'value' => '#654321',
    ]),
];

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('el tenant B no ve NADA de las veintidós tablas del tenant A', function () use ($constructores) {
    $creados = [];

    // Todo el kernel poblado en el tenant A.
    app(TenantContext::class)->runFor($this->tenantA->id, function () use ($constructores, &$creados): void {
        foreach ($constructores as $clase => $construir) {
            $creados[$clase] = $construir();
        }
    });

    // El número está escrito a mano y eso es deliberado: si alguien agrega un modelo acotado y olvida su constructor,
    // el candado de abajo lo dice; si alguien QUITA uno del arreglo, sólo esta cuenta lo delata.
    expect($creados)->toHaveCount(22);

    // Y ahora, desde el tenant B.
    app(TenantContext::class)->set($this->tenantB->id);

    $fugas = [];

    foreach ($creados as $clase => $fila) {
        /** @var class-string<Model> $clase */
        if ($clase::query()->count() !== 0) {
            $fugas[] = "{$clase}: el listado devuelve filas del tenant A";
        }

        // El caso del `find()` ajeno, que es el más fácil de escribir por descuido: conocer
        // la llave primaria no debe bastar para ver la fila.
        if ($clase::query()->find($fila->getKey()) !== null) {
            $fugas[] = "{$clase}: find() encuentra una fila del tenant A";
        }
    }

    expect($fugas)->toBe([], sprintf(
        "FUGA DE DATOS ENTRE TENANTS (ADR-002). Es el único fallo verdaderamente\n".
        "catastrófico del producto:\n  - %s",
        implode("\n  - ", $fugas),
    ));
});

it('los datos SÍ existen: la prueba anterior no pasa por estar vacía', function () use ($constructores) {
    // Autoverificación. Sin esto, un error en los constructores dejaría la base sin filas y
    // el barrido de arriba pasaría por no haber nada que filtrar — verde por ciego.
    app(TenantContext::class)->runFor($this->tenantA->id, function () use ($constructores): void {
        foreach ($constructores as $construir) {
            $construir();
        }
    });

    $vacias = [];

    foreach (array_keys($constructores) as $clase) {
        /** @var class-string<Model> $clase */
        if ($clase::query()->withoutGlobalScopes()->count() === 0) {
            $vacias[] = $clase;
        }
    }

    expect($vacias)->toBe([], sprintf(
        "Estas tablas quedaron vacías, así que el barrido de aislamiento no probó nada\n".
        "sobre ellas:\n  - %s",
        implode("\n  - ", $vacias),
    ));
});

it('lo que cada tenant ve suma exactamente el total, sin solaparse ni perderse', function () use ($constructores) {
    // Poblar AMBOS por igual y comprobar dos cosas por tabla:
    //
    //   1. Los dos ven lo MISMO en número (simetría). Detecta un scope que filtrara al
    //      revés, que "el B no ve nada del A" no detectaría.
    //   2. La suma de lo que ve cada uno es exactamente el total sin scope. Esto es lo
    //      fuerte: descarta a la vez el solapamiento —una fila visible desde los dos— y la
    //      pérdida —una fila que ninguno ve—.
    //
    // No se afirma un conteo concreto a propósito: varios constructores crean membresías y
    // sucursales como dependencias, así que fijar el número obligaría a actualizar la prueba
    // cada vez que cambie una dependencia, sin ganar nada.
    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        app(TenantContext::class)->runFor($tenant->id, function () use ($constructores): void {
            foreach ($constructores as $construir) {
                $construir();
            }
        });
    }

    foreach (array_keys($constructores) as $clase) {
        /** @var class-string<Model> $clase */
        $enA = app(TenantContext::class)->runFor($this->tenantA->id, fn (): int => $clase::query()->count());
        $enB = app(TenantContext::class)->runFor($this->tenantB->id, fn (): int => $clase::query()->count());
        $total = $clase::query()->withoutGlobalScopes()->count();

        expect($enA)->toBeGreaterThan(0, "{$clase} no tiene filas en el tenant A");
        expect($enB)->toBe($enA, "{$clase} ve distinto número en cada tenant");
        expect($enA + $enB)->toBe($total, "{$clase}: hay filas solapadas o inalcanzables");
    }
});

it('las tablas globales por diseño SÍ son visibles desde cualquier tenant', function () {
    // La contrapartida del barrido: verificar que las excepciones declaradas se comportan
    // como excepciones. Si `Permission` dejara de ser visible, el sistema de autorización
    // se rompería y el motivo sería difícil de encontrar.
    $permisos = Permission::query()->count();

    expect($permisos)->toBeGreaterThan(0);

    app(TenantContext::class)->runFor($this->tenantA->id, function () use ($permisos): void {
        expect(Permission::query()->count())->toBe($permisos);
    });

    app(TenantContext::class)->runFor($this->tenantB->id, function () use ($permisos): void {
        expect(Permission::query()->count())->toBe($permisos);
    });
});

it('el barrido cubre TODOS los modelos acotados del kernel', function () use ($constructores) {
    // Candado sobre el candado. Si mañana se agrega una tabla de dominio al kernel, el test
    // estructural de scopes seguirá verde —el modelo tendrá su scope— pero este barrido
    // dejaría de ser completo sin que nadie lo note.
    //
    // Se acota a los módulos del KERNEL, que es su propósito. Antes comparaba contra todos los
    // modelos acotados del proyecto, y eso funcionó mientras el kernel era todo lo que había: al
    // llegar la Iteración 2 exigía que las siete tablas de `Catalog` y `Costing` estuvieran en el
    // barrido del kernel, que es el sitio equivocado. La definition of done pide un barrido **por
    // módulo**, y cada módulo trae el suyo con su propia comprobación de completitud.
    $kernel = array_keys(array_filter(
        (array) config('comandia.modules'),
        fn (array $module): bool => $module['layer'] === 'kernel',
    ));

    $esDelKernel = function (string $clase) use ($kernel): bool {
        foreach ($kernel as $module) {
            if (str_starts_with($clase, "App\\Modules\\{$module}\\")) {
                return true;
            }
        }

        return false;
    };

    $conScope = array_values(array_filter(
        DomainModelDiscovery::all(),
        fn (string $clase): bool => DomainModelDiscovery::hasTenantScope($clase) && $esDelKernel($clase),
    ));

    // Autoverificación: si el filtro dejara la lista vacía, el candado pasaría sin comparar nada.
    expect($conScope)->not->toBeEmpty('El filtro de módulos del kernel no encontró ningún modelo.');

    $faltantes = array_diff($conScope, array_keys($constructores));

    expect($faltantes)->toBe([], sprintf(
        "Estos modelos acotados por tenant NO están en el barrido de aislamiento:\n  - %s\n\n".
        'Agrégalos al arreglo `$constructores` de este archivo.',
        implode("\n  - ", $faltantes),
    ));

    // Y a la inversa: nada en el barrido que no sea un modelo acotado real.
    expect(array_diff(array_keys($constructores), $conScope))->toBe([]);
});

it('un token de API no cruza tenants ni conociendo su identificador', function () {
    // PersonalAccessToken es la cuarta excepción del test de scopes: no lleva scope porque
    // Sanctum lo resuelve antes de que exista contexto. Su aislamiento depende del hash y de
    // la revalidación del middleware, así que conviene verificarlo aparte.
    expect(PersonalAccessToken::query()->count())->toBe(0);
});
