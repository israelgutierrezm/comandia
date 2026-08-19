<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticlePurchasePresentation;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Inventory\Application\CaptureCountLines;
use App\Modules\Inventory\Application\CloseStockCount;
use App\Modules\Inventory\Application\OpenStockCount;
use App\Modules\Inventory\Application\ProductionWorkflow;
use App\Modules\Inventory\Application\RecordStockMovement;
use App\Modules\Inventory\Application\TransferWorkflow;
use App\Modules\Inventory\Domain\Enums\StockMovementKind;
use App\Modules\Inventory\Infrastructure\Models\ArticleLot;
use App\Modules\Inventory\Infrastructure\Models\ArticleStock;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrder;
use App\Modules\Inventory\Infrastructure\Models\ProductionOrderLine;
use App\Modules\Inventory\Infrastructure\Models\StockCount;
use App\Modules\Inventory\Infrastructure\Models\StockCountLine;
use App\Modules\Inventory\Infrastructure\Models\StockMovement;
use App\Modules\Inventory\Infrastructure\Models\Transfer;
use App\Modules\Inventory\Infrastructure\Models\TransferLine;
use App\Modules\Inventory\Infrastructure\Models\WasteReason;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Purchasing\Application\PurchaseReceiptWorkflow;
use App\Modules\Purchasing\Application\RecordSupplierPrice;
use App\Modules\Purchasing\Domain\Enums\SupplierPriceSource;
use App\Modules\Purchasing\Domain\ReceiptLineDraft;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceipt;
use App\Modules\Purchasing\Infrastructure\Models\PurchaseReceiptLine;
use App\Modules\Purchasing\Infrastructure\Models\Supplier;
use App\Modules\Purchasing\Infrastructure\Models\SupplierPrice;
use App\Modules\Shared\Application\Context\ContextHolder;
use App\Modules\Shared\Application\Context\RequestContext;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\DomainModelDiscovery;

/**
 * AISLAMIENTO DE TENANT DE `Inventory` Y `Purchasing`
 *
 * Obligatorio en la definition of done de cada módulo (§11): crear datos en el tenant A, operar en el tenant B,
 * verificar invisibilidad total. Mismo barrido sistemático que el de `Catalog` y `Costing`, con sus cuatro
 * comprobaciones: invisibilidad, autoverificación, simetría y cobertura.
 *
 * ## Casi todo se crea por SERVICIO, no por factory
 *
 * Y no es por falta de factories: es lo que hace la prueba valiosa. Estos dos módulos no tienen una sola tabla que se
 * escriba a mano — el kardex tiene una puerta única, el conteo se cierra por su flujo, la recepción se confirma por
 * evento. Si el barrido creara las filas directamente, comprobaría el aislamiento de los **modelos** y no el de los
 * **caminos**, que es donde una consulta cruzada se cuela: un servicio que resuelva un almacén sin el scope activo, un
 * oyente que corra sin contexto de negocio.
 *
 * Catorce tablas, y las que dependen de un documento se crean con su documento completo: no hay forma de tener una
 * `TransferLine` sin transferencia, ni una `PurchaseReceiptLine` sin recepción confirmada.
 */

/**
 * Monta la infraestructura mínima del negocio activo y devuelve sus piezas.
 *
 * @return array{warehouse: Warehouse, otherWarehouse: Warehouse, supply: Article, producible: Article, supplier: Supplier, presentation: ArticlePurchasePresentation}
 */
function montaNegocio(): array
{
    // DOS almacenes nuevos por llamada, con sucursal propia cada uno.
    //
    // Nada se reusa a propósito, y no es derroche: `montaNegocio()` se llama una vez por constructor, y varios de esos
    // constructores dejan estado que se estorba. El del conteo deja un conteo ABIERTO, y sólo cabe uno por almacén
    // (D176) — con un almacén compartido, el constructor siguiente choca contra esa garantía y el barrido falla por una
    // razón que no tiene nada que ver con el aislamiento.
    //
    // Un almacén por operación deja cada constructor independiente, que es lo que un barrido necesita para que sus
    // fallos signifiquen algo.
    $warehouses = collect(['origen', 'destino'])->map(function (string $etiqueta): Warehouse {
        $branch = Branch::factory()->create();

        return Warehouse::create([
            'branch_id' => $branch->id,
            'kind' => WarehouseKind::Branch,
            'code' => 'ALM-'.$branch->id,
            'name' => "Almacén de {$etiqueta} ({$branch->id})",
            'status' => 'active',
        ]);
    });

    $warehouse = $warehouses[0];
    $otherWarehouse = $warehouses[1];

    $gramo = Unit::query()->where('code', 'g')->firstOrFail();

    $supply = Article::factory()->create(['base_unit_id' => $gramo->id, 'is_inventoriable' => true, 'is_supply' => true]);

    $producible = Article::factory()->create([
        'base_unit_id' => $gramo->id,
        'is_inventoriable' => true,
        'is_producible' => true,
    ]);

    app(SaveRecipe::class)->save($producible, [[
        'component_article_id' => $supply->id,
        'quantity' => '10.0000',
        'unit_id' => $gramo->id,
    ]], outputQuantity: '100', outputUnitId: $gramo->id);

    app(CaptureArticleCost::class)->atUnitCost($supply, '0.5000');

    $supplier = Supplier::create([
        'code' => 'PROV-'.mb_substr((string) $supply->id, -4),
        'legal_name' => 'Proveedor de prueba',
    ]);

    $presentation = ArticlePurchasePresentation::factory()->create([
        'article_id' => $supply->id,
        'quantity_in_base_unit' => '1000',
    ]);

    return compact('warehouse', 'otherWarehouse', 'supply', 'producible', 'supplier', 'presentation');
}

/**
 * Pone contexto de PETICIÓN además de contexto de negocio.
 *
 * Hace falta porque casi todos los servicios de estos módulos exigen una membresía: un conteo dice quién contó y una
 * recepción quién la recibió. Sin esto, el barrido no podría usar los caminos reales — y usar los caminos reales es
 * justamente lo que le da valor.
 */
function conContextoDe(int $tenantId, Closure $callback): mixed
{
    return app(TenantContext::class)->runFor($tenantId, function () use ($tenantId, $callback): mixed {
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);

        $membership = TenantMembership::query()->firstOrFail();

        app(ContextHolder::class)->set(RequestContext::forMember(
            tenant: $tenant,
            user: $membership->user,
            membership: $membership,
            activeRole: null,
            activeBranch: Branch::query()->firstOrFail(),
        ));

        try {
            return $callback();
        } finally {
            app(ContextHolder::class)->forget();
        }
    });
}

/**
 * Un constructor por tabla. Catorce.
 *
 * @return array<class-string<Model>, Closure(): Model>
 */
function constructoresDeInventarioYCompras(): array
{
    return [
        ArticleLot::class => function (): Model {
            $p = montaNegocio();

            return ArticleLot::create([
                'article_id' => $p['supply']->id,
                'code' => 'LOTE-'.mb_substr((string) $p['supply']->id, -4),
                'expires_at' => CarbonImmutable::now()->addMonth()->toDateString(),
                'received_at' => CarbonImmutable::now()->toDateString(),
            ]);
        },

        // El saldo y el movimiento nacen del MISMO camino: la puerta única del kardex. Crearlos por separado sería
        // crear una proyección que no corresponde a ningún movimiento, que es justo lo que el diseño impide.
        StockMovement::class => function (): Model {
            $p = montaNegocio();

            return app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '1000',
            );
        },

        ArticleStock::class => function (): Model {
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '500',
            );

            return ArticleStock::query()
                ->where('warehouse_id', $p['warehouse']->id)
                ->where('article_id', $p['supply']->id)
                ->firstOrFail();
        },

        WasteReason::class => fn (): Model => WasteReason::create(['name' => 'Se cayó al piso']),

        // El conteo y su línea, por el flujo real: abrir congela lo esperado, y sin eso la línea no existiría.
        StockCount::class => function (): Model {
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '40',
            );

            return app(OpenStockCount::class)->open($p['warehouse']);
        },

        StockCountLine::class => function (): Model {
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '40',
            );

            $count = app(OpenStockCount::class)->open($p['warehouse']);

            app(CaptureCountLines::class)->capture($count, [[
                'article' => $p['supply'],
                'lot' => null,
                'counted_quantity' => '37',
            ]]);

            // Se CIERRA para que el barrido cubra también el ajuste masivo, que es el camino con más consecuencias
            // del módulo.
            app(CloseStockCount::class)->close($count);

            return $count->lines()->firstOrFail();
        },

        Transfer::class => function (): Model {
            $p = montaNegocio();

            return app(TransferWorkflow::class)->request(
                origin: $p['warehouse'],
                destination: $p['otherWarehouse'],
                lines: [['article' => $p['supply'], 'lot' => null, 'quantity' => '10']],
            );
        },

        TransferLine::class => function (): Model {
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '100',
            );

            $transfer = app(TransferWorkflow::class)->request(
                origin: $p['warehouse'],
                destination: $p['otherWarehouse'],
                lines: [['article' => $p['supply'], 'lot' => null, 'quantity' => '10']],
            );

            $line = $transfer->lines()->firstOrFail();

            // Enviada Y recibida: así el barrido cubre el almacén de TRÁNSITO, que es una pieza por negocio y el sitio
            // donde una fuga sería más difícil de ver — su saldo no pertenece a ninguna sucursal.
            app(TransferWorkflow::class)->ship($transfer, [$line->id => '10']);
            app(TransferWorkflow::class)->receive($transfer->refresh(), [$line->id => '10']);

            return $line->refresh();
        },

        ProductionOrder::class => function (): Model {
            $p = montaNegocio();

            return app(ProductionWorkflow::class)->plan($p['warehouse'], $p['producible'], '100');
        },

        ProductionOrderLine::class => function (): Model {
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '1000',
            );

            $order = app(ProductionWorkflow::class)->plan($p['warehouse'], $p['producible'], '100');

            app(ProductionWorkflow::class)->complete($order);

            return $order->lines()->firstOrFail();
        },

        Supplier::class => fn (): Model => montaNegocio()['supplier'],

        SupplierPrice::class => function (): Model {
            $p = montaNegocio();

            return app(RecordSupplierPrice::class)->forBaseUnit(
                supplier: $p['supplier'],
                article: $p['supply'],
                unitPrice: '0.4000',
                source: SupplierPriceSource::Quote,
            );
        },

        PurchaseReceipt::class => function (): Model {
            $p = montaNegocio();

            return app(PurchaseReceiptWorkflow::class)->draft(
                supplier: $p['supplier'],
                warehouse: $p['warehouse'],
                lines: [new ReceiptLineDraft(
                    article: $p['supply'],
                    presentation: $p['presentation'],
                    quantity: '2',
                    quantityInBaseUnit: '2000',
                    unitPrice: '400',
                    taxRate: '0',
                )],
                receivedAt: CarbonImmutable::now(),
            );
        },

        PurchaseReceiptLine::class => function (): Model {
            $p = montaNegocio();

            $receipt = app(PurchaseReceiptWorkflow::class)->draft(
                supplier: $p['supplier'],
                warehouse: $p['warehouse'],
                lines: [new ReceiptLineDraft(
                    article: $p['supply'],
                    presentation: $p['presentation'],
                    quantity: '2',
                    quantityInBaseUnit: '2000',
                    unitPrice: '400',
                    taxRate: '16',
                )],
                receivedAt: CarbonImmutable::now(),
            );

            // Se CONFIRMA, y eso es lo que hace esta fila la más valiosa del barrido: dispara los tres oyentes, dos de
            // ellos en OTROS módulos. Un oyente que corriera sin contexto de negocio escribiría en el tenant
            // equivocado, y es el fallo más difícil de ver de todos — ocurre después del commit y fuera del servicio.
            app(PurchaseReceiptWorkflow::class)->confirm($receipt);

            return $receipt->lines()->firstOrFail();
        },
    ];
}

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
    app(ContextHolder::class)->forget();
    app(TenantContext::class)->forget();
});

it('el tenant B no ve NADA de las catorce tablas del tenant A', function () {
    $constructores = constructoresDeInventarioYCompras();

    $creados = [];

    conContextoDe($this->tenantA->id, function () use ($constructores, &$creados): void {
        foreach ($constructores as $clase => $construir) {
            $creados[$clase] = $construir();
        }
    });

    expect($creados)->toHaveCount(14);

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

it('los datos SÍ existían: la prueba anterior no pasa por estar vacía', function () {
    // Autoverificación. Sin esto, un constructor roto dejaría la base sin filas y el barrido pasaría por no haber nada
    // que filtrar — verde por ciego. Es la lección que ya llevaba dos candados falsos en esta iteración (D155, D167).
    $constructores = constructoresDeInventarioYCompras();

    conContextoDe($this->tenantA->id, function () use ($constructores): void {
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

it('lo que cada negocio ve suma el total, sin solaparse ni perderse', function () {
    // Detecta a la vez el solapamiento —una fila visible desde los dos— y la pérdida —una fila que ninguno ve—, que
    // «el B no ve nada del A» no detectaría.
    $constructores = constructoresDeInventarioYCompras();

    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        conContextoDe($tenant->id, function () use ($constructores): void {
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

        expect($enA)->toBeGreaterThan(0, "{$clase} no tiene filas en el negocio A");
        expect($enA + $enB)->toBe($total, "{$clase}: hay filas solapadas o inalcanzables");
    }
});

it('el barrido cubre TODOS los modelos acotados de Inventory y Purchasing', function () {
    // Candado sobre el candado. Si una iteración futura agrega una tabla a estos módulos, el test estructural de
    // scopes seguirá verde —el modelo tendrá su scope— y este barrido dejaría de ser completo sin que nadie lo note.
    $propios = array_values(array_filter(
        DomainModelDiscovery::all(),
        fn (string $clase): bool => DomainModelDiscovery::hasTenantScope($clase)
            && (str_starts_with($clase, 'App\Modules\Inventory\\')
                || str_starts_with($clase, 'App\Modules\Purchasing\\')),
    ));

    expect($propios)->not->toBeEmpty('El filtro no encontró ningún modelo de estos módulos.');

    $constructores = constructoresDeInventarioYCompras();

    $faltantes = array_diff($propios, array_keys($constructores));

    expect($faltantes)->toBe([], sprintf(
        "Estos modelos acotados NO están en el barrido de aislamiento:\n  - %s\n\n".
        'Agrégalos a `constructoresDeInventarioYCompras()` en este archivo.',
        implode("\n  - ", $faltantes),
    ));

    // Y a la inversa: nada en el barrido que no sea un modelo acotado real de estos módulos.
    expect(array_diff(array_keys($constructores), $propios))->toBe([]);
});

it('el almacén de TRÁNSITO es de un solo negocio, aunque no pertenezca a ninguna sucursal', function () {
    // El caso donde una fuga sería más difícil de ver: el tránsito no tiene sucursal (D184), así que un scope mal
    // escrito que se apoyara en la sucursal para acotar lo dejaría compartido entre negocios — y ahí vive la mercancía
    // en viaje de todo el mundo.
    $constructores = constructoresDeInventarioYCompras();

    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        conContextoDe($tenant->id, fn () => $constructores[TransferLine::class]());
    }

    $transitos = Warehouse::query()
        ->withoutGlobalScopes()
        ->where('kind', WarehouseKind::Transit->value)
        ->get();

    // Uno por negocio, y cada uno visible sólo desde el suyo.
    expect($transitos)->toHaveCount(2);

    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        $visibles = app(TenantContext::class)->runFor(
            $tenant->id,
            fn (): int => Warehouse::query()->where('kind', WarehouseKind::Transit->value)->count(),
        );

        expect($visibles)->toBe(1, 'Cada negocio tiene que ver exactamente su propio almacén de tránsito');
    }
});

it('el motivo de merma del sistema es de cada negocio, no compartido', function () {
    // «Diferencia en tránsito» lo crea el sistema al primer uso (D184). Si fuera global, el reporte de mermas de un
    // negocio agruparía las del otro — y es la clase de dato que uno esperaría compartido justamente porque lo crea
    // el sistema y no la persona.
    $constructores = constructoresDeInventarioYCompras();

    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        conContextoDe($tenant->id, function (): void {
            // Una transferencia con diferencia: se envían 10 y llegan 8.
            $p = montaNegocio();

            app(RecordStockMovement::class)->record(
                warehouse: $p['warehouse'],
                article: $p['supply'],
                kind: StockMovementKind::ManualEntry,
                quantity: '100',
            );

            $transfer = app(TransferWorkflow::class)->request(
                origin: $p['warehouse'],
                destination: $p['otherWarehouse'],
                lines: [['article' => $p['supply'], 'lot' => null, 'quantity' => '10']],
            );

            $line = $transfer->lines()->firstOrFail();

            app(TransferWorkflow::class)->ship($transfer, [$line->id => '10']);
            app(TransferWorkflow::class)->receive($transfer->refresh(), [$line->id => '8']);
        });
    }

    $motivos = WasteReason::query()
        ->withoutGlobalScopes()
        ->where('name', 'Diferencia en tránsito')
        ->get();

    expect($motivos)->toHaveCount(2)
        ->and($motivos->pluck('tenant_id')->unique())->toHaveCount(2);
});
