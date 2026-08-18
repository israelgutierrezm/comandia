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
use App\Modules\Organization\Domain\Enums\WarehouseKind;
use App\Modules\Organization\Infrastructure\Models\Branch;
use App\Modules\Organization\Infrastructure\Models\Warehouse;
use App\Modules\Shared\Domain\Tenancy\TenantContext;
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

    private const SLUG = 'fonda-demo';

    public function handle(
        ProvisionTenant $provision,
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

        $context->runFor($tenant->id, function () use ($recipes, $costs): void {
            $this->seedCatalog($recipes, $costs);
        });

        $this->newLine();
        $this->components->info('Negocio de demostración sembrado.');
        $this->table(
            ['Concepto', 'Valor'],
            [
                ['Negocio', $tenant->name],
                ['Estado', $tenant->status->label().' (ya se puede operar)'],
                ['Entrar con', (string) $this->option('email')],
                ['Contraseña', (string) $this->option('password')],
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
    private function purge(Tenant $tenant, TenantContext $context): void
    {
        $this->components->warn("Borrando el negocio de demostración anterior (#{$tenant->id})…");

        DB::transaction(function () use ($tenant): void {
            // El orden va de lo que depende a lo que sostiene. Las tablas con FK a articles antes que
            // articles, y todo antes que tenants.
            $tables = [
                // La proyección apunta al costo vigente (`article_current_costs.source_cost_id`), así
                // que va ANTES que el historial al que apunta.
                // Inventarios antes que el catálogo: el kardex y los saldos apuntan a artículos y lotes.
                'article_stocks', 'stock_movements', 'article_lots',
                'recipe_lines', 'recipes', 'article_current_costs', 'article_costs',
                'price_changes', 'article_branch_overrides', 'article_modifier_group',
                'article_tag', 'article_purchase_presentations', 'modifiers', 'modifier_groups',
                'articles', 'tags', 'article_categories', 'units',
                'audit_entries', 'tenant_status_transitions',
                'warehouses', 'branches',
                'tenant_memberships',
            ];

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

        $this->line('  Catálogo: 12 artículos, 4 categorías, 2 recetas anidadas, 2 grupos de modificadores.');
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
