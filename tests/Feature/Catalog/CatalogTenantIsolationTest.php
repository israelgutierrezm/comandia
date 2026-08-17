<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCost;
use App\Modules\Costing\Infrastructure\Models\ArticleCurrentCost;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainModelDiscovery;

/**
 * AISLAMIENTO DE TENANT DE `Catalog` Y `Costing`
 *
 * Obligatorio en la definition of done de cada módulo (§11): crear datos en el tenant A, operar en el
 * tenant B, verificar invisibilidad total.
 *
 * Es un barrido **sistemático** de las siete tablas nuevas, con las tres mismas comprobaciones que el
 * del kernel: invisibilidad, autoverificación (que los datos existían) y simetría (que lo que ve cada
 * tenant suma el total sin solaparse ni perderse).
 */

/**
 * Constructores de una fila de cada entidad nueva.
 *
 * @var array<class-string<Model>, Closure(): Model>
 */
$constructores = [
    Unit::class => fn (): Model => Unit::factory()->create(),

    ArticleCategory::class => fn (): Model => ArticleCategory::factory()->create(),

    Tag::class => fn (): Model => Tag::factory()->create(),

    Article::class => fn (): Model => Article::factory()->create(),

    ArticlePurchasePresentation::class => fn (): Model => ArticlePurchasePresentation::factory()
        ->create(['article_id' => Article::factory()->create()->id]),

    // El costo y su proyección se crean por el SERVICIO y no por factory: es el único camino que
    // mantiene las dos cosas sincronizadas, y aquí interesa que la proyección también quede poblada
    // para poder comprobar su aislamiento.
    ArticleCost::class => fn (): Model => app(CaptureArticleCost::class)
        ->atUnitCost(Article::factory()->create(), '12.3400'),

    ArticleCurrentCost::class => function (): Model {
        app(CaptureArticleCost::class)->atUnitCost($article = Article::factory()->create(), '56.7800');

        return ArticleCurrentCost::query()->where('article_id', $article->id)->firstOrFail();
    },
];

beforeEach(function () {
    $a = app(ProvisionTenant::class)->provision(
        businessName: 'Fonda del Centro',
        ownerEmail: 'ana@fonda.mx',
        ownerFirstName: 'Ana',
        ownerPaternalSurname: 'Gómez',
        plainPassword: 'contrasena-larga-1',
    );

    $b = app(ProvisionTenant::class)->provision(
        businessName: 'Café del Norte',
        ownerEmail: 'beto@cafe.mx',
        ownerFirstName: 'Beto',
        ownerPaternalSurname: 'Ruiz',
        plainPassword: 'contrasena-larga-2',
    );

    $this->tenantA = $a['tenant'];
    $this->tenantB = $b['tenant'];

    app(TenantContext::class)->forget();
});

afterEach(function () {
    app(TenantContext::class)->forget();
});

it('el tenant B no ve NADA de las siete tablas del tenant A', function () use ($constructores) {
    $creados = [];

    app(TenantContext::class)->runFor($this->tenantA->id, function () use ($constructores, &$creados): void {
        foreach ($constructores as $clase => $construir) {
            $creados[$clase] = $construir();
        }
    });

    expect($creados)->toHaveCount(7);

    app(TenantContext::class)->set($this->tenantB->id);

    $fugas = [];

    foreach ($creados as $clase => $fila) {
        /** @var class-string<Model> $clase */
        if ($clase::query()->whereKey($fila->getKey())->exists()) {
            $fugas[] = "{$clase}: una fila del tenant A es alcanzable por su llave";
        }
    }

    expect($fugas)->toBe([], sprintf(
        "FUGA DE DATOS ENTRE TENANTS (ADR-002):\n  - %s",
        implode("\n  - ", $fugas),
    ));
});

it('los datos SÍ existían: la prueba anterior no pasa por estar vacía', function () use ($constructores) {
    // Autoverificación. Sin esto, un error en los constructores dejaría la base sin filas y el barrido
    // pasaría por no haber nada que filtrar — verde por ciego.
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
        "Estas tablas quedaron vacías, así que el barrido no probó nada sobre ellas:\n  - %s",
        implode("\n  - ", $vacias),
    ));
});

it('lo que cada tenant ve suma el total, sin solaparse ni perderse', function () use ($constructores) {
    // Detecta a la vez el solapamiento —una fila visible desde los dos— y la pérdida —una fila que
    // ninguno ve—, que "el B no ve nada del A" no detectaría.
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
        expect($enA + $enB)->toBe($total, "{$clase}: hay filas solapadas o inalcanzables");
    }
});

it('el pivote de etiquetas también aísla', function () {
    // `article_tag` no tiene modelo propio, así que el barrido de arriba no lo cubre: se comprueba a
    // mano. Lleva `tenant_id` NOT NULL por la Regla A aunque sea alcanzable por FK.
    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        app(TenantContext::class)->runFor($tenant->id, function (): void {
            $article = Article::factory()->create();
            $tag = Tag::factory()->create();

            $article->tags()->sync([$tag->id]);
        });
    }

    $porTenant = DB::table('article_tag')
        ->select('tenant_id', DB::raw('count(*) as total'))
        ->groupBy('tenant_id')
        ->pluck('total', 'tenant_id');

    expect($porTenant)->toHaveCount(2);
    expect((int) $porTenant[$this->tenantA->id])->toBe(1);
    expect((int) $porTenant[$this->tenantB->id])->toBe(1);
});

it('el barrido cubre TODOS los modelos acotados de Catalog y Costing', function () use ($constructores) {
    // Candado sobre el candado, el equivalente del que tiene el kernel. Si una iteración futura
    // agrega una tabla a estos dos módulos, el test estructural de scopes seguirá verde —el modelo
    // tendrá su scope— y este barrido dejaría de ser completo sin que nadie lo note.
    $propios = array_values(array_filter(
        DomainModelDiscovery::all(),
        fn (string $clase): bool => DomainModelDiscovery::hasTenantScope($clase)
            && (str_starts_with($clase, 'App\\Modules\\Catalog\\')
                || str_starts_with($clase, 'App\\Modules\\Costing\\')),
    ));

    expect($propios)->not->toBeEmpty('El filtro no encontró ningún modelo de estos módulos.');

    $faltantes = array_diff($propios, array_keys($constructores));

    expect($faltantes)->toBe([], sprintf(
        "Estos modelos acotados NO están en el barrido de aislamiento:\n  - %s\n\n".
        'Agrégalos al arreglo `$constructores` de este archivo.',
        implode("\n  - ", $faltantes),
    ));

    // Y a la inversa: nada en el barrido que no sea un modelo acotado real de estos módulos.
    expect(array_diff(array_keys($constructores), $propios))->toBe([]);
});

it('las unidades de un negocio no se ven desde el otro, aunque tengan el mismo código', function () {
    // Los dos negocios tienen `kg` sembrado por el alta. El índice único es (tenant, code), así que la
    // coincidencia de código es normal y no debe producir ninguna visibilidad cruzada — y es el caso
    // donde un scope mal escrito se notaría menos, porque el dato "parece" el propio.
    $enA = app(TenantContext::class)->runFor(
        $this->tenantA->id,
        fn (): int => Unit::query()->where('code', 'kg')->count()
    );

    $enB = app(TenantContext::class)->runFor(
        $this->tenantB->id,
        fn (): int => Unit::query()->where('code', 'kg')->count()
    );

    expect($enA)->toBe(1);
    expect($enB)->toBe(1);
    expect(Unit::query()->withoutGlobalScopes()->where('code', 'kg')->count())->toBe(2);
});
