<script setup>
import { computed, ref } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useAuthorization } from '../composables/useAuthorization';
import ContextSwitcher from '../components/ContextSwitcher.vue';
import FlashMessages from '../components/FlashMessages.vue';
import NotificationBell from '../components/NotificationBell.vue';

/**
 * Shell de administración.
 *
 * La navegación se construye desde los permisos del ROL ACTIVO (D9), no desde una lista fija: un
 * mesero que entra aquí no ve enlaces que le darían 403. Es el "guard de navegación" de
 * ARQUITECTURA_MAESTRA §9, y el tercero de los tres niveles de §4.2 —los otros dos son el servicio
 * de autorización y el middleware de módulo activo—.
 */
const page = usePage();
const { can, hasModule, isReadOnly } = useAuthorization();

const menuOpen = ref(false);

const context = computed(() => page.props.context);

/**
 * Cada sección declara el permiso que la habilita. Si un módulo activable llegara a tener sección
 * propia, declararía además su módulo y `hasModule` lo filtraría.
 */
const sections = computed(() => [
    {
        title: 'Organización',
        items: [
            { label: 'Sucursales', route: 'admin.branches', permission: 'organization.branches.view' },
            { label: 'Almacenes', route: 'admin.warehouses', permission: 'organization.warehouses.view' },
            { label: 'Áreas de preparación', route: 'admin.preparation-areas', permission: 'organization.preparation_areas.view' },
            { label: 'Terminales', route: 'admin.terminals', permission: 'organization.terminals.view' },
            { label: 'Impresoras', route: 'admin.printers', permission: 'organization.printers.view' },

            // El editor del salón es CONFIGURACIÓN, no operación: se toca al montar el negocio o al reacomodar, no
            // durante el servicio. Por eso vive aquí y no junto al piso, que es la pantalla del turno.
            { label: 'Salón', route: 'admin.floor.editor', permission: 'floor.layouts.edit' },
        ],
    },
    {
        title: 'Catálogo',
        items: [
            { label: 'Artículos', route: 'admin.catalog.articles', permission: 'catalog.articles.view' },

            // Las tres pantallas de datos de REFERENCIA se enlazan con su permiso de administrar y
            // no con el de leer. Leerlos usa `catalog.articles.view` (D99) porque cualquiera que
            // capture una receta los necesita, pero una pantalla que sólo permita mirar una lista de
            // unidades no le sirve a nadie: quien entra aquí viene a cambiarlas.
            { label: 'Categorías', route: 'admin.catalog.categories', permission: 'catalog.categories.manage' },
            { label: 'Unidades', route: 'admin.catalog.units', permission: 'catalog.units.manage' },
            { label: 'Etiquetas', route: 'admin.catalog.tags', permission: 'catalog.tags.manage' },
            { label: 'Modificadores', route: 'admin.catalog.modifier-groups', permission: 'catalog.modifiers.manage' },

            // Las promociones son precio sobre el catálogo —descuentos, NxM, precio especial sobre artículos y
            // categorías—, así que viven junto a lo que modifican. No se «operan»: se configuran aquí y el POS las
            // aplica solo al cobrar.
            { label: 'Promociones', route: 'admin.promotions', permission: 'promotions.promotions.view' },
        ],
    },
    {
        title: 'Punto de venta',
        items: [
            // La caja va primero: sin turno abierto no se cobra, así que es lo primero que alguien hace al llegar.
            { label: 'Caja', route: 'admin.pos.cash-session', permission: 'pos.sessions.open' },
            { label: 'Cuentas', route: 'admin.pos.accounts', permission: 'pos.orders.create' },

            // El piso va después de las cuentas porque es la vista de conjunto: se abre para saber a quién atender, no
            // para empezar a trabajar.
            { label: 'Piso', route: 'admin.pos.floor', permission: 'floor.layouts.view' },

            // Las comandas se enlazan con el permiso de VER trabajos de impresión, no con el de comandar: quien mira
            // esta pantalla es quien prepara, no quien toma el pedido.
            { label: 'Comandas', route: 'admin.pos.commands', permission: 'printing.jobs.view' },
        ],
    },
    {
        title: 'Inventarios',
        items: [
            { label: 'Existencias', route: 'admin.inventory.stock', permission: 'inventory.stock.view' },
            { label: 'Mermas', route: 'admin.inventory.waste', permission: 'inventory.waste.create' },
            { label: 'Conteos físicos', route: 'admin.inventory.counts', permission: 'inventory.counts.create' },
            { label: 'Transferencias', route: 'admin.inventory.transfers', permission: 'inventory.transfers.request' },
            { label: 'Producción', route: 'admin.inventory.production', permission: 'inventory.production.create' },
        ],
    },
    {
        title: 'Compras',
        items: [
            { label: 'Proveedores', route: 'admin.purchasing.suppliers', permission: 'purchasing.suppliers.view' },
            { label: 'Recepciones', route: 'admin.purchasing.receipts', permission: 'purchasing.receipts.create' },
        ],
    },
    {
        title: 'Clientes',
        items: [
            // El expediente del cliente: sus datos, crédito, perfiles fiscales y direcciones. Es una sección propia y no
            // parte de «Personas» porque ésas son las personas de ADENTRO —personal y roles—; el cliente es de afuera.
            { label: 'Clientes', route: 'admin.customers', permission: 'customers.customers.view' },
        ],
    },
    {
        title: 'Personas',
        items: [
            { label: 'Personal', route: 'admin.staff', permission: 'identity.users.view' },
            { label: 'Roles', route: 'admin.roles', permission: 'identity.roles.view' },
        ],
    },
    {
        // Módulos activables (Iteración 8): cada enlace declara su módulo, así que `hasModule` lo oculta si el negocio no
        // lo tiene contratado. La tienda en línea se suma aquí en la Tanda B.
        title: 'Tienda y menús',
        items: [
            { label: 'Menús', route: 'admin.menus', permission: 'digital_menus.menus.manage', module: 'DigitalMenus' },
            { label: 'Tienda', route: 'admin.store', permission: 'ecommerce.store.configure', module: 'Ecommerce' },
            { label: 'Pedidos', route: 'admin.store-orders', permission: 'ecommerce.orders.view', module: 'Ecommerce' },
            { label: 'Pasarela de pago', route: 'admin.payment-gateway', permission: 'ecommerce.gateways.configure', module: 'Ecommerce' },
        ],
    },
    {
        title: 'Negocio',
        items: [
            // Reportes se enlaza con «ver el diario financiero»: es el permiso que tienen el dueño y el gerente, la
            // audiencia natural de los reportes. La pantalla, de todos modos, sólo lista los reportes que el rol activo
            // puede ver (cada reporte lleva su permiso, ADR-006), así que un enlace de más no filtra nada.
            { label: 'Reportes', route: 'admin.reports', permission: 'finance.journal.view' },
            { label: 'Tableros', route: 'admin.dashboards', permission: 'dashboards.dashboards.view' },
            { label: 'Configuración', route: 'admin.settings', permission: 'configuration.tenant.view' },
            { label: 'Correo', route: 'admin.mail', permission: 'configuration.tenant.view' },
            { label: 'Módulos', route: 'admin.modules', permission: 'tenancy.modules.view' },
            { label: 'Auditoría', route: 'admin.audit', permission: 'audit.entries.view' },
        ],
    },
]);

const visibleSections = computed(() =>
    sections.value
        .map((section) => ({
            ...section,
            items: section.items.filter(
                (item) => can(item.permission) && (!item.module || hasModule(item.module)),
            ),
        }))
        // Una sección sin elementos visibles no se pinta: un encabezado solo confundiría más que
        // ayudar.
        .filter((section) => section.items.length > 0),
);

/**
 * El detalle de un artículo cuelga del listado, así que su URL empieza con la del listado sin ser
 * igual. Con una comparación exacta, estar viendo un artículo apagaría el resaltado de «Artículos» y
 * la barra lateral no marcaría ninguna sección: el usuario perdería de vista dónde está.
 */
function isCurrent(routeName) {
    const url = routeUrl(routeName);

    if (window.location.pathname === url) {
        return true;
    }

    // `/admin` es el inicio y es prefijo de TODAS las demás: sin excluirlo, «Inicio» quedaría
    // resaltado en las nueve pantallas y el resaltado dejaría de significar nada.
    return url !== '/admin' && window.location.pathname.startsWith(`${url}/`);
}

/**
 * Se resuelve la URL desde una tabla y no con un helper de rutas de Laravel: Ziggy añadiría una
 * dependencia y un volcado del mapa de rutas al cliente. Con nueve pantallas, una tabla es más
 * honesto que una biblioteca.
 */
const urls = {
    'admin.dashboard': '/admin',
    'admin.branches': '/admin/sucursales',
    'admin.warehouses': '/admin/almacenes',
    'admin.preparation-areas': '/admin/areas',
    'admin.terminals': '/admin/terminales',
    'admin.printers': '/admin/impresoras',
    'admin.catalog.articles': '/admin/articulos',
    'admin.catalog.categories': '/admin/categorias',
    'admin.catalog.units': '/admin/unidades',
    'admin.catalog.tags': '/admin/etiquetas',
    'admin.catalog.modifier-groups': '/admin/modificadores',
    'admin.promotions': '/admin/promociones',
    'admin.customers': '/admin/clientes',
    'admin.menus': '/admin/menus',
    'admin.store': '/admin/tienda',
    'admin.store-orders': '/admin/pedidos',
    'admin.payment-gateway': '/admin/pasarela',
    'admin.pos.cash-session': '/admin/pos/caja',
    'admin.pos.accounts': '/admin/pos/cuentas',
    'admin.pos.floor': '/admin/pos/piso',
    'admin.pos.commands': '/admin/pos/comandas',
    'admin.floor.editor': '/admin/piso/editor',
    'admin.inventory.stock': '/admin/existencias',
    'admin.inventory.waste': '/admin/mermas',
    'admin.inventory.counts': '/admin/conteos',
    'admin.inventory.transfers': '/admin/transferencias',
    'admin.inventory.production': '/admin/produccion',
    'admin.purchasing.suppliers': '/admin/proveedores',
    'admin.purchasing.receipts': '/admin/recepciones',
    'admin.staff': '/admin/personal',
    'admin.roles': '/admin/roles',
    'admin.reports': '/admin/reportes',
    'admin.dashboards': '/admin/tableros',
    'admin.settings': '/admin/configuracion',
    'admin.mail': '/admin/correo',
    'admin.modules': '/admin/modulos',
    'admin.audit': '/admin/auditoria',
};

function routeUrl(name) {
    return urls[name] ?? '/admin';
}

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="shell">
        <aside class="sidebar" :class="{ 'sidebar--open': menuOpen }">
            <Link href="/admin" class="brand">Comandia</Link>

            <nav>
                <Link
                    href="/admin"
                    class="nav-item"
                    :class="{ 'nav-item--current': isCurrent('admin.dashboard') }"
                >
                    Inicio
                </Link>

                <div v-for="section in visibleSections" :key="section.title" class="nav-section">
                    <p class="nav-section__title">{{ section.title }}</p>

                    <Link
                        v-for="item in section.items"
                        :key="item.route"
                        :href="routeUrl(item.route)"
                        class="nav-item"
                        :class="{ 'nav-item--current': isCurrent(item.route) }"
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
        </aside>

        <div class="main">
            <header class="topbar">
                <button class="menu-toggle" type="button" @click="menuOpen = !menuOpen">
                    <span class="sr-only">Menú</span>☰
                </button>

                <ContextSwitcher />

                <div class="topbar__user">
                    <NotificationBell />

                    <div class="topbar__identity">
                        <span class="topbar__name">{{ context?.membership?.display_name }}</span>
                        <span class="topbar__role">{{ context?.role_name ?? 'Sin rol activo' }}</span>
                    </div>

                    <button class="link-button" type="button" @click="logout">Salir</button>
                </div>
            </header>

            <!--
                Aviso persistente de sólo lectura. Va en el shell y no en cada pantalla porque
                afecta a todas: un tenant en sólo lectura por impago conserva sus datos y no puede
                operar, y descubrirlo botón por botón sería desconcertante.
            -->
            <div v-if="isReadOnly" class="readonly-banner">
                Esta cuenta está en <strong>modo de sólo lectura</strong>. Puedes consultar y
                exportar tu información, pero no registrar operaciones.
            </div>

            <FlashMessages />

            <main class="content">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
.shell {
    display: flex;
    min-height: 100vh;
    background: var(--surface, #f8f7f5);
    color: #1c1917;
    font-family: ui-sans-serif, system-ui, sans-serif;
}

.sidebar {
    width: 15rem;
    flex: none;
    padding: 1.25rem 1rem;
    background: #1c1917;
    color: #e7e5e4;
}

.brand {
    display: block;
    font-size: 1.15rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    color: #fff;
    text-decoration: none;
    margin-bottom: 1.5rem;
}

.nav-section {
    margin-top: 1.25rem;
}

.nav-section__title {
    margin: 0 0 0.35rem 0.5rem;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.5;
}

.nav-item {
    display: block;
    padding: 0.45rem 0.5rem;
    border-radius: 0.375rem;
    color: inherit;
    text-decoration: none;
    font-size: 0.9rem;
}

.nav-item:hover {
    background: rgb(255 255 255 / 8%);
}

.nav-item--current {
    background: rgb(255 255 255 / 14%);
    font-weight: 600;
}

.main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.topbar {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1.5rem;
    background: #fff;
    border-bottom: 1px solid #e7e5e4;
}

.topbar__user {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.topbar__identity {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
    text-align: right;
}

.topbar__name {
    font-size: 0.9rem;
    font-weight: 600;
}

.topbar__role {
    font-size: 0.75rem;
    opacity: 0.6;
}

.link-button {
    background: none;
    border: 0;
    padding: 0;
    color: #c2410c;
    cursor: pointer;
    font: inherit;
    font-size: 0.85rem;
}

.readonly-banner {
    padding: 0.6rem 1.5rem;
    background: #fef3c7;
    border-bottom: 1px solid #fde68a;
    font-size: 0.85rem;
}

.content {
    padding: 1.5rem;
    flex: 1;
}

.menu-toggle {
    display: none;
    background: none;
    border: 0;
    font-size: 1.25rem;
    cursor: pointer;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
}

/* El administrador se usa también desde tableta: la barra lateral se colapsa. El POS táctil
   tendrá su propio layout a pantalla completa (§9). */
@media (max-width: 48rem) {
    .sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        z-index: 20;
        transform: translateX(-100%);
        transition: transform 0.15s ease;
    }

    .sidebar--open {
        transform: translateX(0);
    }

    .menu-toggle {
        display: block;
    }
}
</style>
