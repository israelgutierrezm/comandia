<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Catalog\Domain\Enums\ArticleStatus;
use App\Modules\Catalog\Infrastructure\Models\Article;
use App\Modules\Catalog\Infrastructure\Models\ArticleCategory;
use App\Modules\Catalog\Infrastructure\Models\Modifier;
use App\Modules\Catalog\Infrastructure\Models\ModifierGroup;
use App\Modules\Catalog\Infrastructure\Models\Tag;
use App\Modules\Catalog\Infrastructure\Models\Unit;
use App\Modules\Costing\Application\CaptureArticleCost;
use App\Modules\Costing\Application\SaveRecipe;
use App\Modules\Ecommerce\Infrastructure\Models\ArticleStoreSetting;
use App\Modules\Ecommerce\Infrastructure\Models\Coupon;
use App\Modules\Ecommerce\Infrastructure\Models\PaymentGatewaySetting;
use App\Modules\Ecommerce\Infrastructure\Models\ShippingZone;
use App\Modules\Ecommerce\Infrastructure\Models\Store;
use App\Modules\Floor\Infrastructure\Models\FloorPlan;
use App\Modules\Floor\Infrastructure\Models\FloorZone;
use App\Modules\Floor\Infrastructure\Models\RestaurantTable;
use App\Modules\Identity\Application\CreateMembership;
use App\Modules\Identity\Application\ManageMembershipPin;
use App\Modules\Identity\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\RoleTemplates;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\TenantMembership;
use App\Modules\Organization\Domain\Enums\PrinterConnection;
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\PreparationArea;
use App\Modules\Organization\Infrastructure\Models\Printer;
use App\Modules\Organization\Infrastructure\Models\Terminal;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Platform\Infrastructure\Models\PlatformAdmin;
use App\Modules\Pos\Infrastructure\Models\PosAreaRoute;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
use App\Modules\Tenancy\Application\ManageTenantModules;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tenant de demostración con catálogo y costeo reales (ARQUITECTURA_MAESTRA §11).
 *
 * Existe por dos razones, y la segunda es la que importa:
 *
 *  1. QA y demos comerciales necesitan un negocio con datos que se parezcan a un negocio.
 *  2. **Verificar la interfaz en un navegador exige datos.** Una pantalla de catálogo vacía se ve
 *     idéntica a una pantalla de catálogo rota, y la lección de la UI del kernel fue exactamente
 *     ésa: siete defectos con la suite entera en verde, todos visibles sólo al usarla.
 *
 * No es un seeder de `DatabaseSeeder` a propósito: eso lo ejecutaría cualquier despliegue. Un
 * comando explícito, con confirmación y bloqueado en producción, se ejecuta cuando alguien lo
 * decide.
 *
 * Los datos son de una fonda mexicana con precios y costos verosímiles de 2026, porque un catálogo
 * de «Producto 1 / Producto 2» no revela nada: los defectos de formato, de redondeo y de cascada
 * aparecen cuando los números tienen la forma de los de verdad.
 */
final class SeedDemoTenantCommand extends Command
{
    protected $signature = 'comandia:demo:seed
        {--email=demo@comandia.test : Correo del propietario de demostración}
        {--password=comandia : Contraseña del propietario}
        {--fresh : Borra el negocio de demostración anterior antes de sembrar}
        {--force : Permite ejecutarlo en producción}';

    protected $description = 'Siembra un negocio de demostración con catálogo, recetas, costos y modificadores.';

    /**
     * El PIN del propietario en el negocio de demostración.
     *
     * No es un secreto: es un dato de demostración, igual que la contraseña que este comando ya imprime en pantalla.
     * Y hace falta porque sin PIN sembrado **ninguna operación que exija autorización se puede completar** — ver
     * `seedOwnerPin()`.
     */
    private const OWNER_PIN = '4321';

    private const SLUG = 'fonda-demo';

    public function handle(
        ProvisionTenant $provision,
        ManageMembershipPin $pins,
        CreateMembership $memberships,
        TenantContext $context,
        SaveRecipe $recipes,
        CaptureArticleCost $costs,
    ): int {
        // Un catálogo de demostración en la base de un negocio real sería indistinguible de su
        // catálogo. La bandera existe para quien sabe lo que hace, no para descubrirlo.
        if ($this->getLaravel()->isProduction() && ! $this->option('force')) {
            $this->components->error('En producción hace falta --force: esto crea datos ficticios.');

            return self::FAILURE;
        }

        $existing = Tenant::query()->where('slug', self::SLUG)->first();

        if ($existing !== null && ! $this->option('fresh')) {
            $this->components->warn("Ya existe el negocio de demostración «{$existing->name}».");
            $this->line('Usa --fresh para borrarlo y volver a sembrarlo.');

            return self::SUCCESS;
        }

        if ($existing !== null) {
            $this->purge($existing, $context);
        }

        $result = $provision->provision(
            businessName: 'Fonda La Comandia',
            ownerEmail: (string) $this->option('email'),
            ownerFirstName: 'Remedios',
            ownerPaternalSurname: 'Vargas',
            plainPassword: (string) $this->option('password'),
            ownerMaternalSurname: 'Ríos',
            slug: self::SLUG,
            branchName: 'Roma Norte',
            branchCode: 'ROMA',
        );

        $tenant = $result['tenant'];

        // Se queda en «pendiente de activación», que es como nace todo tenant (D70) y que **ya
        // permite entrar y operar**: la activación es un hecho comercial, no un permiso técnico. No
        // se fuerza a «activo» aquí porque hoy no existe el servicio que cambia de estado —lo traerá
        // el panel de super admin (D6)— y escribir la transición a mano desde un comando de demos
        // sería la primera copia de una regla que después habría que mantener en dos sitios.

        $context->runFor($tenant->id, function () use ($recipes, $costs, $pins, $memberships, $result): void {
            $this->seedCatalog($recipes, $costs);
            $this->seedOwnerPin($pins, $result['membership']);
            $this->seedStaff($memberships, $pins);
            $this->seedStore();
        });

        // El super admin de la PLATAFORMA: fuera del contexto de tenant, porque no pertenece a ningún negocio. Con él
        // se prueba el acceso separado en `/plataforma/acceso` y el alta de negocios.
        PlatformAdmin::updateOrCreate(
            ['email' => 'platform@comandia.test'],
            ['name' => 'Operador de plataforma', 'password' => (string) $this->option('password')],
        );

        $this->newLine();
        $this->components->info('Negocio de demostración sembrado.');
        $this->table(
            ['Concepto', 'Valor'],
            [
                ['Negocio', $tenant->name],
                ['Estado', $tenant->status->label().' (ya se puede operar)'],
                ['Entrar con', (string) $this->option('email')],
                ['Contraseña', (string) $this->option('password')],
                ['PIN de autorización', self::OWNER_PIN.' (código '.($result['membership']->employee_code ?? '—').')'],
                ['Personal del POS', 'gerente@ / cajero@ / mesero@ / mesero-cobro@comandia.test'],
                ['Sus PIN', 'G001:1111 · C001:2222 · M001:3333 · W001:4444'],
                ['Tienda en línea', url('/t/'.self::SLUG).' (pasarela de prueba activa)'],
                ['Super admin (plataforma)', 'platform@comandia.test → '.url('/plataforma/acceso')],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Borra el negocio de demostración anterior.
     *
     * Con `DELETE` directo y en orden inverso a las dependencias, no con los modelos: las tablas
     * inmutables —historial de precios, historial de costos, bitácora— **rechazan** el borrado por
     * diseño, y así tiene que seguir siendo. Este comando es el único lugar del sistema autorizado
     * a saltárselo, y sólo porque lo que borra son datos ficticios de un tenant entero.
     */
    /**
     * El PIN de autorización del propietario.
     *
     * ## Por qué el sembrador tiene que ponerlo
     *
     * Varias operaciones responden 409 pidiendo el PIN de un superior (ADR-008): una merma sobre el umbral, el cierre
     * de un conteo grande, y desde la Iteración 5 los descuentos y las cancelaciones. Sin **ninguna** persona con PIN
     * dado de alta, ese diálogo no se puede completar: se convierte en un callejón sin salida donde la única respuesta
     * posible es «código o PIN incorrectos».
     *
     * Y ese mensaje engaña sin querer. Es el correcto —ADR-008 exige que un código inexistente y un PIN equivocado
     * digan lo mismo, para que nadie pueda enumerar códigos válidos— pero quien lo lee concluye que escribió mal el
     * PIN, no que no hay a quién pedírselo. Lo encontré así: intentando autorizar una merma en el negocio de
     * demostración, con la pantalla insistiendo en que mi PIN estaba mal.
     *
     * O sea que la autorización por PIN, que es una de las cosas que este producto vende, era justo la que no se podía
     * demostrar.
     */
    /**
     * Áreas de preparación, terminales e impresoras: la infraestructura sin la que el punto de venta no funciona.
     *
     * ## Por qué la siembra el demo y no el alta de un negocio
     *
     * Porque son decisiones del negocio, no del sistema. La topología es flexible a propósito (D11): una fonda tiene
     * una cocina y una caja; un bar tiene barra, cocina y dos cajas. Sembrarlas al dar de alta impondría una forma.
     *
     * Pero el negocio de DEMOSTRACIÓN sí las necesita, y lo descubrí construyendo las impresoras: sin áreas no hay
     * dónde rutear una comanda, sin terminal no hay sesión de caja y sin impresora no hay a dónde imprimir. O sea que
     * el punto de venta no se podía demostrar en absoluto — el mismo tipo de hueco que D224, donde faltaba el PIN.
     *
     * ## El ruteo queda armado, no sólo las piezas
     *
     * Cada área apunta a su impresora y cada terminal a la suya. Sembrar las tres tablas sin asignarlas dejaría una
     * demostración que parece completa y no imprime nada.
     */
    private function seedOperationalInfrastructure(Branch $second, Warehouse $secondWarehouse): void
    {
        $roma = Branch::query()->where('code', 'ROMA')->sole();
        $romaWarehouse = Warehouse::query()->where('branch_id', $roma->id)->sole();

        foreach ([[$roma, $romaWarehouse], [$second, $secondWarehouse]] as [$branch, $warehouse]) {
            // La de cocina no lleva conector de cajón: es de red, está en la cocina y nadie guarda dinero ahí.
            $kitchenPrinter = Printer::create([
                'branch_id' => $branch->id,
                'code' => 'COCINA',
                'name' => 'Impresora de cocina',
                'connection' => PrinterConnection::Network,
                'target' => '192.168.1.50:9100',
                'paper_width' => 80,
                'supports_cash_drawer' => false,
            ]);

            // La de la caja sí: el cajón se abre mandándole una secuencia a ella.
            $cashPrinter = Printer::create([
                'branch_id' => $branch->id,
                'code' => 'CAJA',
                'name' => 'Impresora de caja',
                'connection' => PrinterConnection::Usb,
                'target' => 'POS-80',
                'paper_width' => 80,
                'supports_cash_drawer' => true,
            ]);

            PreparationArea::create([
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'printer_id' => $kitchenPrinter->id,
                'code' => 'COCINA',
                'name' => 'Cocina',
                'sort_order' => 10,
            ]);

            // La barra descuenta del MISMO almacén que la cocina en esta demostración, y no es pereza: D11 permite un
            // almacén por área, y mostrarlo así exigiría dos almacenes por sucursal que nadie usaría. El caso fino se
            // configura desde la pantalla de áreas.
            PreparationArea::create([
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'printer_id' => $kitchenPrinter->id,
                'code' => 'BARRA',
                'name' => 'Barra',
                'sort_order' => 20,
            ]);

            Terminal::create([
                'branch_id' => $branch->id,
                'printer_id' => $cashPrinter->id,
                'code' => 'CAJA1',
                'name' => 'Caja 1',
            ]);

            $this->seedFloor($branch);
        }
    }

    /**
     * El ruteo de artículos a áreas (D240).
     *
     * Sin esto, comandar no produce ni un papel: todos los items saldrían sin área y el punto de venta se podría operar
     * pero no se podría **demostrar** — la cocina nunca recibiría nada. Es la quinta vez en esta iteración que el negocio
     * de demostración destapa que algo no se podía demostrar.
     *
     * Se rutea por CATEGORÍA, que es como lo haría un negocio real: dos reglas por sucursal en lugar de una por artículo.
     *
     * ## Se llama al final del catálogo y no con el resto de la infraestructura
     *
     * Lo escribí primero junto a las impresoras y las áreas, donde parecía pertenecer, y **no habría sembrado nada**: la
     * infraestructura se siembra antes de que existan las categorías. Con un `first()` y un `return` silencioso no
     * habría fallado tampoco — simplemente no habría ruteo, y comandar no sacaría ni un papel sin que nada lo dijera.
     *
     * De ahí el `sole()`: si falta un prerrequisito, este comando revienta. Un sembrador de demostraciones que se calla
     * es cómo el punto de venta llegó cuatro veces a no poderse demostrar en esta misma iteración.
     */
    private function seedAreaRoutes(): void
    {
        $categorias = ArticleCategory::query()->whereNull('parent_id')->pluck('id', 'name');

        foreach (Branch::query()->get() as $branch) {
            foreach (['Bebidas' => 'BARRA', 'Alimentos' => 'COCINA'] as $categoria => $codigoArea) {
                $area = PreparationArea::query()
                    ->where('branch_id', $branch->id)
                    ->where('code', $codigoArea)
                    ->sole();

                PosAreaRoute::create([
                    'branch_id' => $branch->id,
                    'article_category_id' => $categorias[$categoria],
                    'preparation_area_id' => $area->id,
                ]);
            }
        }
    }

    /**
     * El personal con el que se opera el punto de venta.
     *
     * ## Por qué hace falta, y no es un adorno de la demostración
     *
     * La mitad de las reglas de §6.3 son sobre **quién puede qué**: el mesero captura y no cobra, el cajero cobra y no
     * descuenta, lo sensible exige el PIN de un superior, y la propina se atribuye al mesero titular de la cuenta
     * (D233). Con un solo propietario ninguna de esas reglas se puede ver — «titular» y «quien cobra» serían siempre la
     * misma persona, y un rol que lo puede todo no demuestra que los límites funcionen.
     *
     * Es el mismo hueco que faltaba con el PIN (D224), las impresoras y las mesas: cosas sin las que el punto de venta
     * no se puede ni demostrar, y que sólo aparecen al intentar usarlo.
     *
     * ## Todos con PIN
     *
     * Porque cualquiera de ellos puede ser quien **autorice** una operación sensible con su código y su PIN en la
     * terminal de otra persona (ADR-008). Sembrar el personal sin PIN dejaría el mismo callejón sin salida que D224.
     */
    private function seedStaff(CreateMembership $memberships, ManageMembershipPin $pins): void
    {
        $roles = Role::query()->pluck('ulid', 'name');

        $personas = [
            // El gerente: puede casi todo, y es quien autoriza los descuentos y las cancelaciones del turno.
            ['Gerardo', 'Mena', 'gerente@comandia.test', 'G001', RoleTemplates::MANAGER, '1111'],

            // El cajero: cobra y opera la caja. Sin descuentos ni cancelación de comandado.
            ['Carla', 'Ruiz', 'cajero@comandia.test', 'C001', RoleTemplates::CASHIER, '2222'],

            // El mesero: captura y comanda. NO cobra — es la mitad de D29.
            ['Mario', 'Solís', 'mesero@comandia.test', 'M001', RoleTemplates::WAITER, '3333'],

            // Y el mesero CON cobro, que es la otra plantilla de D29: el mismo trabajo más la capacidad de cerrar la
            // cuenta. Tener las dos sembradas es lo que hace visible la diferencia.
            ['Wendy', 'Cano', 'mesero-cobro@comandia.test', 'W001', RoleTemplates::WAITER_WITH_CHARGE, '4444'],
        ];

        foreach ($personas as [$nombre, $apellido, $correo, $codigo, $rol, $pin]) {
            $membership = $memberships->create(
                email: $correo,
                plainPassword: (string) $this->option('password'),
                firstName: $nombre,
                paternalSurname: $apellido,
                maternalSurname: null,
                employeeCode: $codigo,
                roleUlids: [$roles[$rol]],
                hasAllBranches: true,
            );

            // Con credenciales, la membresía nace INVITADA (CreateMembership): en producción la persona la acepta al
            // entrar por primera vez. En el demo se activa de una vez, porque un negocio sembrado cuyo personal no
            // puede iniciar sesión no sirve para demostrar el POS —que es justo para lo que existe este personal—.
            $membership->update(['status' => MembershipStatus::Active]);

            $pins->set($membership->fresh(), $pin);
        }
    }

    /**
     * El salón: un plano con dos zonas y seis mesas por sucursal.
     *
     * Hace falta por lo mismo que las impresoras: sin mesas no se puede abrir una cuenta de restaurante, y el arquetipo
     * primario del producto es «restaurante con mesas, meseros y cuentas abiertas» (§1). Un negocio de demostración sin
     * salón sólo permitiría demostrar la barra.
     *
     * Seis mesas y no veinte: es lo que cabe en una pantalla sin desplazarse, que es lo que se quiere en una demo.
     */
    private function seedFloor(Branch $branch): void
    {
        $plan = FloorPlan::create([
            'branch_id' => $branch->id,
            'name' => 'Planta baja',
            'is_default' => true,
        ]);

        $salon = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Salón', 'sort_order' => 10]);
        $terraza = FloorZone::create(['floor_plan_id' => $plan->id, 'name' => 'Terraza', 'sort_order' => 20]);

        // Coordenadas repartidas en cuadrícula: la Iteración 6 las va a mover con el editor, pero un salón donde todas
        // las mesas están en el 0,0 no se puede ni mirar.
        $mesas = [
            [$salon, 'M1', 4, 40, 40],
            [$salon, 'M2', 4, 160, 40],
            [$salon, 'M3', 2, 280, 40],
            [$salon, 'M4', 6, 40, 160],
            [$terraza, 'T1', 4, 160, 160],
            [$terraza, 'T2', 2, 280, 160],
        ];

        foreach ($mesas as [$zona, $code, $seats, $x, $y]) {
            RestaurantTable::create([
                'branch_id' => $branch->id,
                'floor_zone_id' => $zona->id,
                'code' => $code,
                'seats' => $seats,
                'x' => $x,
                'y' => $y,
                'shape' => $seats === 2 ? 'circle' : 'rectangle',
            ]);
        }
    }

    private function seedOwnerPin(ManageMembershipPin $pins, TenantMembership $membership): void
    {
        // La membresía viene de `provision()`, no de una consulta por código: el código de empleado lo elige
        // `ProvisionTenant` y buscarlo aquí lo pondría en dos sitios, con el sembrador rompiéndose en silencio el día
        // que cambie.
        if ($membership->employee_code === null) {
            // `ManageMembershipPin::set()` lo exige, y con razón: un PIN sin código no se puede teclear en ninguna
            // parte. Si eso cambia, es un cambio de `ProvisionTenant` y no algo que este comando deba remendar.
            $this->components->warn('No se sembró el PIN: la membresía del propietario no tiene código de empleado.');

            return;
        }

        $pins->set($membership, self::OWNER_PIN);
    }

    private function purge(Tenant $tenant, TenantContext $context): void
    {
        $this->components->warn("Borrando el negocio de demostración anterior (#{$tenant->id})…");

        DB::transaction(function () use ($tenant): void {
            // El orden va de lo que depende a lo que sostiene. Las tablas con FK a articles antes que
            // articles, y todo antes que tenants.
            $tables = $this->purgeTables();

            foreach ($tables as $table) {
                $this->breakSelfReference($table, $tenant->id);

                DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }

            // Los roles de Spatie usan el equipo como columna de tenant.
            DB::table('roles')->where('tenant_id', $tenant->id)->delete();

            DB::table('tenants')->where('id', $tenant->id)->delete();
        });

        $context->forget();
    }

    /**
     * Las tablas a purgar, en orden inverso a sus dependencias.
     *
     * Está en su propio método —y no como variable local— para que el candado de
     * `tests/Feature/Shared/DemoSeederPurgeTest.php` pueda LEERLA en lugar de duplicarla. Dos listas que dicen lo mismo
     * se desincronizan, que es exactamente el problema que ese candado existe para evitar.
     *
     * @return list<string>
     */
    private function purgeTables(): array
    {
        return [
            // 1. Los RENGLONES de documento, antes que nada: los cuatro apuntan a `stock_movements` con
            //    `RESTRICT`, así que borrar el kardex antes que ellos falla.
            //
            //    Es justo lo que pasó al cerrar la Iteración 3: `--fresh` dejó de poder purgar porque esta
            //    lista no conocía las tablas nuevas, y el mensaje era un error de FK sin pista de qué faltaba.
            //    Hay una prueba que corre la purga completa para que no vuelva a pasar en silencio.
            'purchase_receipt_lines', 'production_order_lines', 'stock_count_lines', 'transfer_lines',

            // 2. El historial de precios de proveedor, que apunta a las recepciones.
            'supplier_prices',

            // 3. Los documentos, ya sin nadie que los sostenga.
            'purchase_receipts', 'production_orders', 'stock_counts', 'transfers',

            // 4. El kardex y su proyección. Ahora sí: nada apunta ya a los movimientos.
            'article_stocks', 'stock_movements',

            // 5. Lo que el kardex citaba: los motivos de merma y los lotes.
            'waste_reasons', 'article_lots',

            // 6. Los proveedores, después de sus compras y sus precios.
            'suppliers',

            // El punto de venta, antes que el catálogo y que el salón: los tickets citan a las órdenes y a las áreas,
            // los items a los artículos, las reglas de ruteo a las categorías y las cuentas a las mesas. Va de la punta
            // a la raíz.
            //
            // El demo sólo siembra `pos_area_routes`; las demás están vacías al sembrar — y aun así entran, porque
            // `--fresh` se corre después de haber ABIERTO el navegador y operado, que es cuando hay cuentas reales.
            //
            // Su sitio en la lista lo decidió el candado: puse el bloque antes del salón, que parecía suficiente, y
            // `DemoSeederPurgeTest` falló con un 1451 sobre `article_categories`. La prueba existe exactamente para
            // esto, y es la tercera iteración seguida en que esta lista se rompe.
            // Los trabajos de impresión citan a los tickets y a las impresoras: van antes que los dos.
            'print_jobs', 'print_agents',

            'pos_ticket_items', 'pos_tickets',

            // Lo que cuelga de las cuentas y de sus líneas, antes que ellas.
            'pos_account_operation_items', 'pos_account_operations',
            'pos_discounts', 'pos_payments',

            'pos_order_item_modifiers', 'pos_order_items', 'pos_orders',
            'pos_accounts',
            'pos_area_routes', 'pos_takeout_counters',

            // Pedidos de la tienda (Iteración 8): los pagos y las líneas citan al pedido; el pedido cita al cliente
            // (RESTRICT), así que van ANTES que `customers`.
            'payments', 'order_items', 'orders',

            // El crédito de los clientes: los movimientos citan al cliente y a la sesión de caja.
            'customer_credit_movements', 'customer_credits', 'customers',

            // Gastos, depósitos y liquidaciones: citan a la sesión, al método de pago y a la categoría de gasto, así
            // que van antes que las tres.
            'expenses', 'bank_deposits', 'tip_settlements',

            // Promociones (Iteración 6). El registro por venta y los objetivos citan a la promoción y a artículos/
            // categorías con RESTRICT, así que van ANTES que la promoción y ANTES que artículos/categorías.
            // `promotion_branches` cascadea de branches y no necesita listarse.
            'promotion_applications', 'promotion_targets', 'promotions',

            // La proyección apunta al costo vigente (`article_current_costs.source_cost_id`), así
            // que va ANTES que el historial al que apunta.
            'recipe_lines', 'recipes', 'article_current_costs', 'article_costs',
            'price_changes', 'article_branch_overrides', 'article_modifier_group',
            'article_tag', 'article_purchase_presentations', 'modifiers', 'modifier_groups',
            // Capa de publicación y ajustes de tienda (Iteración 8): cuelgan de `articles` (cascada), pero se listan
            // explícitos para que el candado de la purga los cubra y no queden como tablas de tenant sin barrer.
            'article_store_settings', 'article_images', 'article_publications',
            'articles', 'tags', 'article_categories', 'units',
            'audit_entries', 'tenant_status_transitions',

            // Las secuencias de folio apuntan a sucursales, así que antes que ellas.
            'document_sequences',

            // Menús digitales y tienda (Iteración 8): citan a la sucursal, así que van antes que `branches`.
            // `store_branches`, `shipping_zones` y `coupons` antes que `stores` (FK), y todo antes que `branches`.
            'digital_menus', 'store_branches', 'shipping_zones', 'coupons', 'stores', 'payment_gateway_settings',

            // El salón, antes que las sucursales: las mesas citan a la zona y a la sucursal, y la zona a su plano.
            // `restaurant_tables` va primero porque se cita a sí misma —la unión de mesas— y porque cita a la zona.
            'restaurant_tables', 'floor_zones', 'floor_plans',

            // Los métodos de pago y las categorías de gasto, que el alta siembra por negocio.
            'financial_movements', 'payment_methods', 'expense_categories',

            // Las sesiones de caja, después del diario: `financial_movements.pos_session_id` las cita.
            'pos_session_withdrawals', 'pos_session_declarations', 'pos_sessions',

            // La infraestructura operativa, en su orden: las áreas y las terminales citan a las impresoras, y las
            // tres citan a la sucursal con `RESTRICT`. Entraron en la lista al empezar a sembrarse (paso 2 de la
            // Iteración 4) — antes no hacían falta porque el demo no las creaba, que es exactamente la forma en que
            // esta lista se rompe cada iteración.
            'preparation_areas', 'terminals', 'printers',

            'warehouses', 'branches',
            'tenant_memberships',
        ];
    }

    /**
     * Deshace la referencia de una tabla a SÍ MISMA antes de borrarla.
     *
     * Dos tablas del catálogo tienen una FK a su propia tabla y las dos son RESTRICT, así que un
     * `DELETE` de la tabla completa falla: una fila sostiene a otra y el orden interno no lo decide
     * quien escribe la sentencia. Cada una necesita una estrategia distinta, y ésa es la parte que no
     * se adivina:
     *
     *   - **`article_costs.source_cost_id`** se pone en `NULL`. La cadena causal puede tener cualquier
     *     profundidad —un costo derivado de otro derivado de otro— y borrar «hijas primero» sólo
     *     resolvería dos niveles.
     *   - **`article_categories.parent_id` NO se puede poner en `NULL`**: hay un CHECK que amarra
     *     `level` con `parent_id`, y una subcategoría sin padre lo viola. Aquí sí sirve borrar las
     *     hijas primero, y basta una pasada porque D18 limita el árbol a dos niveles — el mismo CHECK
     *     que impide la otra estrategia es el que garantiza que ésta alcanza.
     */
    private function breakSelfReference(string $table, int $tenantId): void
    {
        if ($table === 'article_costs') {
            DB::table($table)->where('tenant_id', $tenantId)->update(['source_cost_id' => null]);

            return;
        }

        if ($table === 'article_stocks') {
            // No es una FK a sí misma, pero el efecto es el mismo: `article_stocks.last_movement_id` apunta a
            // `stock_movements`, y los movimientos se borran DESPUÉS. Se anula la referencia antes.
            DB::table($table)->where('tenant_id', $tenantId)->update(['last_movement_id' => null]);

            return;
        }

        if ($table === 'article_categories') {
            DB::table($table)->where('tenant_id', $tenantId)->whereNotNull('parent_id')->delete();
        }
    }

    /**
     * La tienda en línea de la demostración (Iteración 8).
     *
     * Sin esto, `/t/{slug}` no existe y el flujo de e-commerce —carrito, checkout, pasarela— no se puede ver ni demostrar.
     * Activa el módulo (un negocio sin él no ejecuta su código, D3), atiende las dos sucursales, publica un puñado de
     * vendibles, arma una zona de envío y deja la **pasarela de prueba** como activa: la tienda cobra de extremo a extremo
     * sin credenciales reales ni cargos. Un negocio real cambiaría la pasarela por Mercado Pago o Stripe con sus llaves.
     *
     * Se corre después de `seedCatalog`, del que toma los artículos ya creados.
     */
    private function seedStore(): void
    {
        app(ManageTenantModules::class)->set('Ecommerce', true);

        $roma = Branch::query()->where('code', 'ROMA')->sole();
        $pola = Branch::query()->where('code', 'POLA')->sole();

        $store = Store::create([
            'slug' => self::SLUG, // única global; coincide con el slug del negocio a propósito, para recordarla fácil
            'name' => 'Fonda La Comandia',
            'is_active' => true,
            'theme_primary' => '#b91c1c',
        ]);
        $store->storeBranches()->create(['branch_id' => $roma->id]);
        $store->storeBranches()->create(['branch_id' => $pola->id]);

        ShippingZone::create([
            'store_id' => $store->id, 'name' => 'Roma y alrededores', 'cost' => '39.00', 'is_active' => true,
        ]);

        // Un subconjunto del catálogo se publica en la tienda (Tanda B): se venden siempre; el POS decide su inventario.
        foreach (['Enchiladas suizas', 'Chilaquiles verdes', 'Refresco 600 ml', 'Agua de jamaica 1 l'] as $name) {
            ArticleStoreSetting::create([
                'article_id' => Article::query()->where('name', $name)->sole()->id,
                'is_in_store' => true,
                'stock_policy' => 'sell_always',
            ]);
        }

        // La pasarela de PRUEBA queda activa: el checkout cobra de extremo a extremo sin llaves reales ni cargos.
        PaymentGatewaySetting::create(['active_gateway' => 'fake']);

        // Un cupón de bienvenida para probar el canje en el checkout (D3).
        Coupon::create([
            'store_id' => $store->id, 'code' => 'BIENVENIDO', 'type' => 'percentage', 'value' => '15.00', 'is_active' => true,
        ]);
    }

    private function seedCatalog(SaveRecipe $recipes, CaptureArticleCost $costs): void
    {
        // ---- Una segunda sucursal, para que el precio por sucursal tenga dónde verse ----
        $second = Branch::create([
            'code' => 'POLA',
            'name' => 'Polanco',
            'timezone' => 'America/Mexico_City',
        ]);

        $warehouse = Warehouse::create([
            'branch_id' => $second->id,
            'kind' => WarehouseKind::Branch,
            'code' => 'POLA-ALM',
            'name' => 'Almacén Polanco',
        ]);

        $second->update(['default_warehouse_id' => $warehouse->id]);

        $this->seedOperationalInfrastructure($second, $warehouse);

        // ---- Categorías, dos niveles ----
        $alimentos = ArticleCategory::create(['name' => 'Alimentos', 'level' => 1, 'sort_order' => 10]);
        $antojitos = ArticleCategory::create([
            'name' => 'Antojitos', 'parent_id' => $alimentos->id, 'level' => 2, 'sort_order' => 10,
        ]);
        $fuertes = ArticleCategory::create([
            'name' => 'Platos fuertes', 'parent_id' => $alimentos->id, 'level' => 2, 'sort_order' => 20,
        ]);
        $bebidas = ArticleCategory::create(['name' => 'Bebidas', 'level' => 1, 'sort_order' => 20]);

        $vegetariano = Tag::create(['name' => 'Vegetariano']);
        $picante = Tag::create(['name' => 'Picante']);

        $g = $this->unit('g');
        $kg = $this->unit('kg');
        $ml = $this->unit('ml');
        $l = $this->unit('l');
        $pza = $this->unit('pza');

        // ---- Insumos que se compran ----
        $jitomate = $this->article('Jitomate saladet', $g, supply: true, inventoriable: true);
        $chile = $this->article('Chile serrano', $g, supply: true, inventoriable: true);
        $cebolla = $this->article('Cebolla blanca', $g, supply: true, inventoriable: true);
        $queso = $this->article('Queso Oaxaca', $g, supply: true, inventoriable: true);
        $crema = $this->article('Crema ácida', $ml, supply: true, inventoriable: true);
        $tortilla = $this->article('Tortilla de maíz', $pza, supply: true, inventoriable: true);
        $pollo = $this->article('Pechuga de pollo', $g, supply: true, inventoriable: true);

        // Presentaciones de compra: es como llega la factura, y sin ellas quien captura costos divide
        // a mano — el momento exacto en que un costo entra con dos ceros de más.
        $jitomate->purchasePresentations()->create([
            'name' => 'Caja de 12 kg', 'quantity_in_base_unit' => '12000.0000', 'is_default' => true,
        ]);
        $queso->purchasePresentations()->create([
            'name' => 'Pieza de 1 kg', 'quantity_in_base_unit' => '1000.0000', 'is_default' => true,
        ]);

        // Costos de adquisición verosímiles, por unidad base.
        $costs->atUnitCost($jitomate, '0.0320', notes: 'Central de abasto, marzo');
        $costs->atUnitCost($chile, '0.0850', notes: 'Central de abasto, marzo');
        $costs->atUnitCost($cebolla, '0.0280', notes: 'Central de abasto, marzo');
        $costs->atUnitCost($queso, '0.1750', notes: 'Lácteos del Valle');
        $costs->atUnitCost($crema, '0.0620', notes: 'Lácteos del Valle');
        $costs->atUnitCost($tortilla, '0.7500', notes: 'Tortillería La Guadalupana');
        $costs->atUnitCost($pollo, '0.1480', notes: 'Pollería San Juan');

        // ---- Producible que además es insumo: la salsa ----
        //
        // Es el caso que hace evidente D17: la salsa no es «producto» ni «insumo», es las dos cosas.
        // Y su costo se calcula, no se captura.
        $salsa = $this->article('Salsa verde', $ml, supply: true, producible: true);

        $recipes->save(
            article: $salsa,
            lines: [
                // El jitomate rinde 85 %: cáscara y semilla no llegan al plato (D21).
                ['component_article_id' => $jitomate->id, 'quantity' => '900.0000', 'unit_id' => $g->id, 'yield_percent' => '85.00', 'sort_order' => 0],
                ['component_article_id' => $chile->id, 'quantity' => '80.0000', 'unit_id' => $g->id, 'yield_percent' => '90.00', 'sort_order' => 1],
                ['component_article_id' => $cebolla->id, 'quantity' => '150.0000', 'unit_id' => $g->id, 'yield_percent' => '88.00', 'sort_order' => 2],
            ],
            // Una tanda rinde un litro, capturado en litros para que la conversión se ejercite de
            // verdad: el costo tiene que salir por mililitro.
            outputQuantity: '1.0000',
            outputUnitId: $l->id,
            notes: 'Asar, licuar y sazonar. Rinde un litro por tanda.',
        );

        // ---- Vendibles ----
        $enchiladas = $this->article(
            'Enchiladas suizas', $pza,
            sellable: true, producible: true,
            category: $fuertes, price: '145.00', shortName: 'Ench. suizas',
        );

        $recipes->save(
            article: $enchiladas,
            lines: [
                ['component_article_id' => $tortilla->id, 'quantity' => '3.0000', 'unit_id' => $pza->id, 'sort_order' => 0],
                // La salsa entra por 120 ml y su costo viene de SU receta: es la cascada de dos
                // niveles funcionando.
                ['component_article_id' => $salsa->id, 'quantity' => '120.0000', 'unit_id' => $ml->id, 'sort_order' => 1],
                ['component_article_id' => $queso->id, 'quantity' => '70.0000', 'unit_id' => $g->id, 'sort_order' => 2],
                ['component_article_id' => $crema->id, 'quantity' => '40.0000', 'unit_id' => $ml->id, 'sort_order' => 3],
                ['component_article_id' => $pollo->id, 'quantity' => '160.0000', 'unit_id' => $g->id, 'yield_percent' => '92.00', 'sort_order' => 4],
            ],
            notes: 'Tres tortillas rellenas de pollo, bañadas en salsa verde y gratinadas.',
        );

        $chilaquiles = $this->article(
            'Chilaquiles verdes', $pza,
            sellable: true, producible: true,
            category: $antojitos, price: '98.00',
        );

        $recipes->save(
            article: $chilaquiles,
            lines: [
                ['component_article_id' => $tortilla->id, 'quantity' => '4.0000', 'unit_id' => $pza->id, 'sort_order' => 0],
                ['component_article_id' => $salsa->id, 'quantity' => '200.0000', 'unit_id' => $ml->id, 'sort_order' => 1],
                ['component_article_id' => $crema->id, 'quantity' => '50.0000', 'unit_id' => $ml->id, 'sort_order' => 2],
                ['component_article_id' => $queso->id, 'quantity' => '40.0000', 'unit_id' => $g->id, 'sort_order' => 3],
            ],
        );

        $chilaquiles->tags()->attach([$vegetariano->id, $picante->id]);

        // Vendibles que se compran hechos: capacidad vendible + inventariable, sin receta.
        $refresco = $this->article(
            'Refresco 600 ml', $pza,
            sellable: true, inventoriable: true,
            category: $bebidas, price: '38.00',
        );
        $costs->atUnitCost($refresco, '14.5000', notes: 'Distribuidor, caja de 24');

        $cerveza = $this->article(
            'Cerveza clara 355 ml', $pza,
            sellable: true, inventoriable: true, supply: true,
            category: $bebidas, price: '62.00',
        );
        $costs->atUnitCost($cerveza, '19.8000', notes: 'Distribuidor, cartón de 20');

        // Un vendible SIN costo, a propósito: es el caso que prueba que «sin costo» se dice con
        // palabras y no como «$0.00», y que el semáforo del precio se declara no evaluable.
        $this->article(
            'Agua de jamaica 1 l', $l,
            sellable: true, producible: true,
            category: $bebidas, price: '45.00',
        );

        // ---- Modificadores ----
        $extras = ModifierGroup::create([
            'name' => 'Extras',
            'is_required' => false,
            'min_selections' => 0,
            'max_selections' => null,
            'allows_quantity' => true,
        ]);

        $extraQueso = Modifier::create([
            'modifier_group_id' => $extras->id, 'name' => 'Extra queso',
            'extra_price' => '28.00', 'sort_order' => 0,
        ]);
        $extraCrema = Modifier::create([
            'modifier_group_id' => $extras->id, 'name' => 'Extra crema',
            'extra_price' => '18.00', 'sort_order' => 1,
        ]);
        Modifier::create([
            'modifier_group_id' => $extras->id, 'name' => 'Sin cebolla',
            'extra_price' => '0.00', 'sort_order' => 2,
        ]);

        // «Extra queso» consume queso de verdad. Sin esta receta, el platillo con extras costaría lo
        // mismo que sin ellos y el margen del extra saldría del 100 %.
        $recipes->saveForModifier($extraQueso, [
            ['component_article_id' => $queso->id, 'quantity' => '35.0000', 'unit_id' => $g->id, 'sort_order' => 0],
        ]);
        $recipes->saveForModifier($extraCrema, [
            ['component_article_id' => $crema->id, 'quantity' => '30.0000', 'unit_id' => $ml->id, 'sort_order' => 0],
        ]);

        $termino = ModifierGroup::create([
            'name' => 'Nivel de picante',
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
            'allows_quantity' => false,
        ]);

        foreach (['Sin picante', 'Medio', 'Bien picoso'] as $index => $name) {
            Modifier::create([
                'modifier_group_id' => $termino->id, 'name' => $name,
                'extra_price' => '0.00', 'sort_order' => $index,
            ]);
        }

        $enchiladas->modifierGroups()->attach([
            $termino->id => ['sort_order' => 0],
            $extras->id => ['sort_order' => 1],
        ]);
        $chilaquiles->modifierGroups()->attach([
            $termino->id => ['sort_order' => 0],
            $extras->id => ['sort_order' => 1],
        ]);

        // ---- Un precio propio de sucursal, para que la herencia se vea ----
        $enchiladas->branchOverrides()->create([
            'branch_id' => $second->id,
            'price' => '165.00',
        ]);

        // Y una disponibilidad propia: agotado sólo en Polanco.
        $cerveza->branchOverrides()->create([
            'branch_id' => $second->id,
            'is_available_in_pos' => false,
        ]);

        // Al final, porque el ruteo necesita que las categorías existan.
        $this->seedAreaRoutes();

        $this->line('  Catálogo: 12 artículos, 4 categorías, 2 recetas anidadas, 2 grupos de modificadores.');
        $this->line('  Ruteo: Bebidas → Barra, Alimentos → Cocina, en las dos sucursales.');
    }

    private function unit(string $code): Unit
    {
        // Las cinco unidades del sistema las sembró el listener de `TenantProvisioned` (D97). Si
        // faltara alguna, es un defecto de ese sembrado y no algo que este comando deba arreglar.
        return Unit::query()->where('code', $code)->sole();
    }

    private function article(
        string $name,
        Unit $baseUnit,
        bool $sellable = false,
        bool $inventoriable = false,
        bool $supply = false,
        bool $producible = false,
        ?ArticleCategory $category = null,
        ?string $price = null,
        ?string $shortName = null,
    ): Article {
        return Article::create([
            'name' => $name,
            'short_name' => $shortName,
            'base_unit_id' => $baseUnit->id,
            'category_id' => $category?->id,
            'is_sellable' => $sellable,
            'is_inventoriable' => $inventoriable,
            'is_supply' => $supply,
            'is_producible' => $producible,
            'base_price' => $price,
            'is_available_in_pos' => $sellable,
            'status' => ArticleStatus::Active,
        ]);
    }
}
