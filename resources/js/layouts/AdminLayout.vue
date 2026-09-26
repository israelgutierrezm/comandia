<script setup>
import { computed, provide, ref, onMounted, onUnmounted, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { api } from '../api/client';
import { useAuthorization } from '../composables/useAuthorization';
import { ICON_PATHS } from '../icons';
import ContextSwitcher from '../components/ContextSwitcher.vue';
import FlashMessages from '../components/FlashMessages.vue';
import NotificationBell from '../components/NotificationBell.vue';
import ThemePanel from '../components/ThemePanel.vue';
import BarraCarga from '../components/BarraCarga.vue';
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';

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

// En teléfono/tableta vertical el menú es un cajón que tapa la pantalla. El layout PERSISTE entre páginas (Inertia),
// así que sin esto seguía abierto al navegar y tapaba lo que se acababa de abrir. Se cierra al cambiar de página y con
// Escape; además hay un fondo que lo cierra al tocar fuera.
watch(() => page.url, () => {
    menuOpen.value = false;
});

function cerrarMenuConEscape(evento) {
    if (evento.key === 'Escape' && menuOpen.value) {
        menuOpen.value = false;
    }
}

onMounted(() => window.addEventListener('keydown', cerrarMenuConEscape));
onUnmounted(() => window.removeEventListener('keydown', cerrarMenuConEscape));

// El sidebar colapsable a un rail de iconos: en un POS ocupa demasiado espacio expandido. El estado se persiste para que
// se quede como el operador lo dejó.
const collapsed = ref(false);
const flyout = ref(null); // título de la sección con su flyout abierto (sólo en modo colapsado)
const narrow = ref(false); // viewport de móvil, donde el sidebar es un cajón y el rail no aplica

// El rail sólo en escritorio: en móvil el sidebar ya es un cajón deslizable, y encogerlo a 4rem lo rompería.
const railMode = computed(() => collapsed.value && ! narrow.value);

function updateNarrow() {
    narrow.value = window.innerWidth < 768;
}

onMounted(() => {
    // Contraída por omisión: en un POS el rail de iconos es lo ideal. Sólo se expande si esta persona lo eligió antes.
    const guardado = localStorage.getItem('comandia:sidebar-collapsed');
    collapsed.value = guardado === null ? true : guardado === '1';
    updateNarrow();
    window.addEventListener('resize', updateNarrow);
    document.addEventListener('click', cerrarFlyoutFuera);
});

onUnmounted(() => {
    window.removeEventListener('resize', updateNarrow);
    document.removeEventListener('click', cerrarFlyoutFuera);
});

function toggleCollapsed() {
    collapsed.value = ! collapsed.value;
    flyout.value = null;
    try {
        localStorage.setItem('comandia:sidebar-collapsed', collapsed.value ? '1' : '0');
    } catch {
        // Almacenamiento bloqueado (modo privado): el colapso funciona igual, sólo no se recuerda entre recargas.
    }
}

function toggleFlyout(title) {
    flyout.value = flyout.value === title ? null : title;
}

/**
 * Con el rail contraído, un clic FUERA del rail cierra el flyout abierto —como cerrarlo tocando de nuevo su icono—.
 * Se escucha en el documento y no consume el clic: la pantalla de atrás responde igual, sólo que el flyout se va. El
 * clic que ABRE el flyout no lo cierra porque su blanco está dentro de `.rail`.
 */
function cerrarFlyoutFuera(e) {
    if (flyout.value !== null && ! e.target.closest('.rail')) {
        flyout.value = null;
    }
}

/** Icono de cada sección, por su título — así el rail no obliga a ponerle icono a los 33 ítems del menú. */
function sectionIcon(title) {
    return {
        'Organización': 'building',
        'Catálogo': 'tag',
        'Punto de venta': 'receipt',
        'Inventarios': 'box',
        'Compras': 'truck',
        'Clientes': 'users',
        'Personas': 'user',
        'Tienda y menús': 'shop',
        'Finanzas': 'cash',
        'Negocio': 'chart',
    }[title] ?? 'dot';
}

/** Los trazos viven en `../icons`, compartidos con el encabezado de los listados. Alias local para no tocar el resto. */
const ICONS = ICON_PATHS;

const context = computed(() => page.props.context);

const theme = computed(() => page.props.theme);

/**
 * Inyecta la paleta del TEMA resuelto en las CSS custom properties (estilo Acadion). Los defaults viven en `@theme`;
 * esto los sobrescribe token por token. Los colores del tema llegan en snake_case (`barra_lateral`) y las variables CSS
 * van en kebab-case (`--color-barra-lateral`). Se re-aplica al recargar el shell —elegir tema o color recarga sólo este
 * prop—. Los colores semánticos (éxito, peligro, aviso) no son del tema: quedan como están.
 */
function applyTheme() {
    const tokens = page.props.theme?.tokens ?? {};
    const raiz = document.documentElement;

    // Se parte de limpio: un token que el tema nuevo ya no trae no debe quedarse con el valor del anterior.
    quitarTema();

    for (const [nombre, valor] of Object.entries(tokens)) {
        ponerEnRaiz(`--color-${nombre.replaceAll('_', '-')}`, valor);
    }

    // El texto del ítem ACTIVO del menú: claro u oscuro, el que más contraste dé con el color activo de ESTE tema.
    // Ningún valor fijo sirve para todos: el blanco no se lee sobre el teal, el cian o el amarillo (≈1.4–2:1), y el
    // oscuro no se lee sobre el índigo. Se calcula de la luminancia (WCAG), así cubre también los temas personalizados.
    const activo = tokens.barra_lateral_activo
        ?? getComputedStyle(raiz).getPropertyValue('--color-barra-lateral-activo');
    ponerEnRaiz('--color-barra-lateral-activo-texto', textoSobre(activo));
}

/*
 * Las propiedades que este shell puso en la raíz, para quitarlas al salir del admin. La raíz sobrevive a la navegación
 * de Inertia: sin esta limpieza, una pantalla con otro marco —elegir negocio, que vive sobre una tarjeta blanca— heredaba
 * los colores del tema, y con uno oscuro quedaba texto claro sobre blanco.
 */
const propiedadesDelTema = new Set();

function ponerEnRaiz(propiedad, valor) {
    document.documentElement.style.setProperty(propiedad, valor);
    propiedadesDelTema.add(propiedad);
}

function quitarTema() {
    propiedadesDelTema.forEach((propiedad) => document.documentElement.style.removeProperty(propiedad));
    propiedadesDelTema.clear();
}

const TEXTO_CLARO = '#ffffff';
const TEXTO_OSCURO = '#0b1620';

/** Luminancia relativa (WCAG 2.x) de un color `#rgb`/`#rrggbb`; `null` si no se puede leer. */
function luminancia(color) {
    const hex = String(color ?? '').trim().replace('#', '');
    const completo = hex.length === 3 ? hex.split('').map((c) => c + c).join('') : hex;

    if (! /^[0-9a-fA-F]{6}$/.test(completo)) {
        return null;
    }

    const [r, g, b] = [0, 2, 4].map((i) => {
        const canal = parseInt(completo.slice(i, i + 2), 16) / 255;

        return canal <= 0.03928 ? canal / 12.92 : ((canal + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** El texto (claro u oscuro) con más contraste sobre `fondo`. */
function textoSobre(fondo) {
    const l = luminancia(fondo);

    if (l === null) {
        return TEXTO_CLARO;
    }

    const contraClaro = 1.05 / (l + 0.05);
    const contraOscuro = (l + 0.05) / (luminancia(TEXTO_OSCURO) + 0.05);

    return contraOscuro > contraClaro ? TEXTO_OSCURO : TEXTO_CLARO;
}
onMounted(applyTheme);
onUnmounted(quitarTema);
watch(() => page.props.theme?.tokens, applyTheme, { deep: true });

// El panel de apariencia, que se abre desde la barra superior (como Acadion).
const panelTema = ref(false);

/**
 * Tamaño de letra, sólo para esta persona y este navegador. Como todo se mide en `rem`, mover la raíz escala el conjunto
 * de forma proporcional. Se recuerda en `sessionStorage`: es una preferencia de accesibilidad, no un ajuste del negocio.
 */
const ESCALA_MIN = 80;
const ESCALA_MAX = 140;
const ESCALA_PASO = 10;
const escalaFuente = ref(100);

function aplicarEscala(valor) {
    escalaFuente.value = Math.min(ESCALA_MAX, Math.max(ESCALA_MIN, valor));
    document.documentElement.style.fontSize = `${escalaFuente.value}%`;

    try {
        sessionStorage.setItem('comandia:escala-fuente', String(escalaFuente.value));
    } catch {
        // Almacenamiento bloqueado (modo privado): la escala funciona igual, sólo no se recuerda.
    }
}

function ajustarFuente(delta) {
    aplicarEscala(escalaFuente.value + delta);
}

onMounted(() => aplicarEscala(Number(sessionStorage.getItem('comandia:escala-fuente')) || 100));

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
            // El tablero de cocina exige `pos.kds.view` en TODAS sus llamadas; gatearlo por `printing.jobs.view` se lo
            // mostraba al Cajero y al Mesero, que al entrar recibían 403.
            { label: 'Comandas', route: 'admin.pos.commands', permission: 'pos.kds.view' },

            // Qué se mandó a imprimir, qué salió y qué falló. Es del turno —«¿por qué la cocina no recibe papeles?»—, no
            // de la configuración: por eso va aquí y no junto a «Impresoras», aunque su URL cuelgue de ella.
            { label: 'Trabajos de impresión', route: 'admin.print-jobs', permission: 'printing.jobs.view' },
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
            { label: 'Canales de marketplace', route: 'admin.channels', permission: 'ecommerce.store.configure', module: 'Ecommerce' },
            { label: 'Pedidos', route: 'admin.store-orders', permission: 'ecommerce.orders.view', module: 'Ecommerce' },
            { label: 'Cupones', route: 'admin.coupons', permission: 'ecommerce.coupons.manage', module: 'Ecommerce' },
            { label: 'Pasarela de pago', route: 'admin.payment-gateway', permission: 'ecommerce.gateways.configure', module: 'Ecommerce' },
        ],
    },
    {
        // El dinero fuera del cobro: con qué se cobra, en qué se gasta, qué se deposita, qué propinas se deben y el diario
        // que lo junta todo (sólo lectura: al diario escriben los eventos, ADR-004).
        title: 'Finanzas',
        items: [
            { label: 'Movimientos', route: 'admin.finance.journal', permission: 'finance.journal.view' },
            { label: 'Gastos', route: 'admin.finance.expenses', permission: 'finance.journal.view' },
            { label: 'Depósitos', route: 'admin.finance.deposits', permission: 'finance.journal.view' },
            { label: 'Propinas', route: 'admin.finance.tips', permission: 'finance.tips.settle' },
            // Con el permiso de administrar, como las pantallas de datos de referencia del catálogo: quien entra aquí viene
            // a cambiarlos (la caja los LEE con su propio permiso).
            { label: 'Métodos de pago', route: 'admin.finance.payment-methods', permission: 'finance.payment_methods.manage' },
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
            { label: 'Apariencia', route: 'admin.appearance', permission: 'configuration.tenant.view' },
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
 * La entrada del menú de la pantalla actual. Una sola búsqueda para el resaltado, la sección abierta, las migajas y el
 * icono del encabezado —antes eran cuatro copias de la misma comparación—.
 *
 * El detalle de un artículo cuelga del listado, así que su URL empieza con la del listado sin ser igual: con una
 * comparación exacta, estar viendo un artículo apagaría «Artículos». Y cuando dos entradas coinciden por prefijo
 * (`/admin/impresoras` y `/admin/impresoras/trabajos`) gana la de URL MÁS LARGA: la más específica es la pantalla.
 * `/admin` es el inicio y es prefijo de todas: nunca coincide por prefijo, o «Inicio» quedaría resaltado siempre.
 */
function entradaDe(path) {
    let mejor = null;

    for (const section of sections.value) {
        for (const item of section.items) {
            const url = routeUrl(item.route);
            const coincide = path === url || (url !== '/admin' && path.startsWith(`${url}/`));

            if (coincide && (mejor === null || url.length > mejor.url.length)) {
                mejor = { section, item, url };
            }
        }
    }

    return mejor;
}

/** Depende de `page.url` (reactivo de Inertia): `window.location` no recomputaría al navegar. */
const entradaActual = computed(() => entradaDe(page.url.split('?')[0]));

function isCurrent(routeName) {
    if (routeName === 'admin.dashboard') {
        return page.url.split('?')[0] === '/admin';
    }

    return entradaActual.value?.item.route === routeName;
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
    'admin.channels': '/admin/canales',
    'admin.store-orders': '/admin/pedidos',
    'admin.coupons': '/admin/cupones',
    'admin.payment-gateway': '/admin/pasarela',
    'admin.pos.cash-session': '/admin/pos/caja',
    'admin.pos.accounts': '/admin/pos/cuentas',
    'admin.pos.floor': '/admin/pos/piso',
    'admin.pos.commands': '/admin/pos/comandas',
    'admin.print-jobs': '/admin/impresoras/trabajos',
    'admin.finance.journal': '/admin/finanzas/movimientos',
    'admin.finance.expenses': '/admin/finanzas/gastos',
    'admin.finance.deposits': '/admin/finanzas/depositos',
    'admin.finance.tips': '/admin/finanzas/propinas',
    'admin.finance.payment-methods': '/admin/finanzas/metodos-de-pago',
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
    'admin.appearance': '/admin/apariencia',
    'admin.modules': '/admin/modulos',
    'admin.audit': '/admin/auditoria',
};

function routeUrl(name) {
    return urls[name] ?? '/admin';
}

// -----------------------------------------------------------------
// Acordeón del menú expandido (estilo Acadion): un grupo abierto a la vez, con un icono por sección y la sección de la
// pantalla actual abierta y resaltada. En modo rail, el flyout hace este papel.
// -----------------------------------------------------------------

/** La sección cuya pantalla se está viendo, para abrirla y resaltarla. Depende de `page.url` (reactivo de Inertia). */
const activeSectionTitle = computed(() => entradaActual.value?.section.title ?? null);

const openSection = ref(null);

function toggleSection(title) {
    openSection.value = openSection.value === title ? null : title;
}

// Al montar y en cada navegación, abre la sección de la pantalla actual. Un grupo a la vez, como Acadion.
watch(activeSectionTitle, (title) => {
    if (title) {
        openSection.value = title;
    }
}, { immediate: true });

/**
 * Migajas de pan. Se DERIVAN de la navegación, no se declaran pantalla por pantalla: para la ruta activa se busca su
 * sección e ítem en el mismo árbol que pinta la barra lateral, así una pantalla nueva obtiene sus migajas sin tocar nada.
 * Las pantallas de detalle (que cuelgan de un listado, p. ej. una cuenta abierta) heredan la migaja del listado padre por
 * el prefijo de la URL, la misma regla que resalta la sección en la barra. Depende de `page.url` (reactivo de Inertia)
 * para recomputarse en cada navegación; `window.location` no lo haría.
 */
const breadcrumbs = computed(() => {
    const path = page.url.split('?')[0];
    const crumbs = [{ label: 'Inicio', href: '/admin' }];

    if (path === '/admin') {
        return crumbs;
    }

    // Cuentas y el detalle de una cuenta van SIN migajas (lo pidió el usuario): su encabezado ya sitúa la pantalla y ahí
    // el espacio de arriba es para operar. Devolver sólo «Inicio» deja la barra oculta (se pinta con más de un nivel).
    if (path === '/admin/pos/cuentas' || path.startsWith('/admin/pos/cuentas/')) {
        return crumbs;
    }

    const entrada = entradaActual.value;

    if (entrada) {
        crumbs.push({ label: entrada.section.title });
        crumbs.push({ label: entrada.item.label, href: entrada.url });
    }

    return crumbs;
});

/**
 * Icono de la sección de la pantalla activa, para que el encabezado del listado lo pinte sin cablearlo pantalla por
 * pantalla (lo eligió el usuario: automático por sección). Se resuelve como las migajas —misma búsqueda en el árbol de
 * navegación— y se INYECTA para que `ListHeader` lo tome; una pantalla puede seguir imponiendo el suyo con la prop `icon`.
 */
const seccionActivaIcono = computed(() => {
    const path = page.url.split('?')[0];

    if (path === '/admin') {
        return 'home';
    }

    return entradaActual.value ? sectionIcon(entradaActual.value.section.title) : 'dot';
});
provide('seccionActivaIcono', seccionActivaIcono);

// Las migajas se COMPARTEN con el encabezado del listado (`ListHeader`), que las mete en su tarjeta para que vayan en la
// misma línea del título. La bandera deja que el listado avise «yo las pinto», y así el layout no las repite arriba; en
// las pantallas sin tarjeta de encabezado (fichas de detalle) el layout las sigue pintando en su propia línea.
provide('breadcrumbs', breadcrumbs);
const migajasEnCabecera = ref(false);
provide('migajasEnCabecera', migajasEnCabecera);

function logout() {
    router.post('/logout');
}

// -----------------------------------------------------------------
// Modo KIOSCO de terminal compartida (ADR-012)
//
// Cuando la sesión es de un dispositivo, el shell cambia de marco: sin navegación de admin ni selector de
// negocio —un operador no administra nada—, sólo su nombre, "Salir" y auto-bloqueo por inactividad. La
// identidad del operador sale de `context`, igual que la de un usuario; lo que no hay es cuenta.
// -----------------------------------------------------------------
const sharedTerminal = computed(() => page.props.shared_terminal);
const kiosk = computed(() => sharedTerminal.value?.active ?? false);

// Vuelve al bloqueo: olvida al operador en el servidor y va a la pantalla de bloqueo. Un solo disparo,
// venga del botón "Salir" o del temporizador de inactividad.
let volviendoAlBloqueo = false;
async function irAlBloqueo() {
    if (volviendoAlBloqueo) {
        return;
    }

    volviendoAlBloqueo = true;

    try {
        await api.delete('/shared-terminal/operator');
    } catch {
        // Aunque falle (p. ej. ya caducó), el destino es el mismo: la pantalla de bloqueo.
    }

    router.visit('/terminal');
}

// Auto-bloqueo: tras el umbral SIN interacción, la terminal vuelve al bloqueo. Es el gemelo en el cliente
// de la caducidad del servidor (que corre sobre la última petición a la API): cubre el caso de una
// terminal abandonada que ya no toca la API. Cualquier toque o tecla reinicia la cuenta.
let temporizadorInactividad = null;
const EVENTOS_ACTIVIDAD = ['pointerdown', 'keydown'];

function reiniciarInactividad() {
    clearTimeout(temporizadorInactividad);
    const segundos = sharedTerminal.value?.idle_seconds ?? 90;
    temporizadorInactividad = setTimeout(irAlBloqueo, segundos * 1000);
}

onMounted(() => {
    if (! kiosk.value) {
        return;
    }

    EVENTOS_ACTIVIDAD.forEach((evento) => window.addEventListener(evento, reiniciarInactividad, { passive: true }));
    reiniciarInactividad();
});

onUnmounted(() => {
    clearTimeout(temporizadorInactividad);
    EVENTOS_ACTIVIDAD.forEach((evento) => window.removeEventListener(evento, reiniciarInactividad));
});
</script>

<template>
    <!-- Marco KIOSCO: terminal compartida operada por PIN (ADR-012). Sin barra lateral ni selector de
         negocio; sólo el operador, "Salir" y el contenido del POS. -->
    <div v-if="kiosk" class="kiosk-shell">
        <header class="kiosk-shell__bar">
            <span class="kiosk-shell__brand">
                <span class="kiosk-shell__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 18h16.5M5.25 18a6.75 6.75 0 0 1 13.5 0M12 6.75V4.5m-2.25 0h4.5" />
                    </svg>
                </span>
                Comandia
            </span>

            <div class="kiosk-shell__op">
                <span class="kiosk-shell__op-name">{{ context?.membership?.display_name }}</span>
                <span class="kiosk-shell__op-role">{{ context?.role_name ?? 'Operando' }}</span>
            </div>

            <button type="button" class="kiosk-shell__salir" @click="irAlBloqueo">Salir</button>
        </header>

        <FlashMessages />
        <BarraCarga />

        <main class="kiosk-shell__content">
            <slot />
        </main>

        <Toaster position="bottom-right" rich-colors close-button />
    </div>

    <div v-else class="shell">
        <aside class="sidebar" :class="{ 'sidebar--open': menuOpen, 'sidebar--collapsed': railMode }">
            <div class="sidebar__head">
                <Link href="/admin" class="brand">
                    <span class="brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 18h16.5M5.25 18a6.75 6.75 0 0 1 13.5 0M12 6.75V4.5m-2.25 0h4.5" />
                        </svg>
                    </span>
                    <span class="brand__name">Comandia</span>
                </Link>

                <button
                    type="button"
                    class="collapse-toggle"
                    :title="collapsed ? 'Expandir menú' : 'Colapsar menú'"
                    @click="toggleCollapsed"
                >
                    <span class="sr-only">{{ collapsed ? 'Expandir menú' : 'Colapsar menú' }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" />
                    </svg>
                </button>
            </div>

            <!-- Expandido: acordeón con un icono por sección (estilo Acadion). -->
            <nav v-if="!railMode" class="nav">
                <Link
                    href="/admin"
                    class="nav-top"
                    :class="{ 'nav-top--current': isCurrent('admin.dashboard') }"
                >
                    <svg class="nav-top__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.home" />
                    </svg>
                    <span class="nav-top__label">Inicio</span>
                </Link>

                <div v-for="section in visibleSections" :key="section.title" class="nav-group">
                    <button
                        type="button"
                        class="nav-top nav-group__head"
                        :class="{ 'nav-top--active': section.title === activeSectionTitle }"
                        :aria-expanded="openSection === section.title"
                        @click="toggleSection(section.title)"
                    >
                        <svg class="nav-top__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[sectionIcon(section.title)]" />
                        </svg>
                        <span class="nav-top__label">{{ section.title }}</span>
                        <svg
                            class="nav-group__chevron"
                            :class="{ 'nav-group__chevron--open': openSection === section.title }"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                        </svg>
                    </button>

                    <div v-show="openSection === section.title" class="nav-group__items">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="routeUrl(item.route)"
                            class="nav-subitem"
                            :class="{ 'nav-subitem--current': isCurrent(item.route) }"
                        >
                            {{ item.label }}
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- Colapsado: rail de iconos por sección; tocar uno abre un flyout con sus pantallas. -->
            <nav v-else class="rail">
                <Link
                    href="/admin"
                    class="rail-icon"
                    title="Inicio"
                    :class="{ 'rail-icon--current': isCurrent('admin.dashboard') }"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.home" />
                    </svg>
                </Link>

                <div v-for="section in visibleSections" :key="section.title" class="rail-section">
                    <button
                        type="button"
                        class="rail-icon"
                        :class="{ 'rail-icon--open': flyout === section.title }"
                        :title="section.title"
                        @click="toggleFlyout(section.title)"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[sectionIcon(section.title)]" />
                        </svg>
                    </button>

                    <div v-if="flyout === section.title" class="flyout">
                        <p class="flyout__title">{{ section.title }}</p>
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="routeUrl(item.route)"
                            class="flyout__item"
                            :class="{ 'flyout__item--current': isCurrent(item.route) }"
                            @click="flyout = null"
                        >
                            {{ item.label }}
                        </Link>
                    </div>
                </div>
            </nav>
        </aside>

        <!-- Fondo del cajón móvil: tocar fuera lo cierra (sólo visible en pantallas angostas). -->
        <div v-if="menuOpen" class="sidebar-backdrop" aria-hidden="true" @click="menuOpen = false"></div>

        <div class="main">
            <header class="topbar">
                <button class="menu-toggle" type="button" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
                    <span class="sr-only">Menú</span>☰
                </button>

                <ContextSwitcher />

                <div class="topbar__user">
                    <NotificationBell />

                    <!-- Apariencia: abre el panel de temas (como Acadion). Mismo estilo que la campana para que la barra
                         superior se vea uniforme: emoji sin caja ni borde. -->
                    <button
                        class="topbar__icono"
                        type="button"
                        aria-label="Apariencia"
                        title="Apariencia"
                        @click="panelTema = true"
                    >
                        🎨
                    </button>

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
            <BarraCarga />

            <main class="content">
                <!-- Migajas de pan: salvo en el propio Inicio, y salvo cuando el encabezado del listado ya las pinta en
                     su tarjeta (`migajasEnCabecera`). Aquí quedan sólo para las pantallas sin tarjeta de encabezado. -->
                <nav v-if="breadcrumbs.length > 1 && !migajasEnCabecera" class="migajas" aria-label="Ruta de navegación">
                    <template v-for="(crumb, i) in breadcrumbs" :key="i">
                        <Link
                            v-if="crumb.href && i < breadcrumbs.length - 1"
                            :href="crumb.href"
                            class="migaja migaja--enlace"
                        >
                            {{ crumb.label }}
                        </Link>
                        <span
                            v-else
                            class="migaja"
                            :class="{ 'migaja--actual': i === breadcrumbs.length - 1 }"
                            :aria-current="i === breadcrumbs.length - 1 ? 'page' : undefined"
                        >
                            {{ crumb.label }}
                        </span>
                        <span v-if="i < breadcrumbs.length - 1" class="migaja__sep" aria-hidden="true">›</span>
                    </template>
                </nav>

                <slot />
            </main>
        </div>

        <ThemePanel
            :abierto="panelTema"
            :escala="escalaFuente"
            :paso="ESCALA_PASO"
            @cerrar="panelTema = false"
            @ajustar="ajustarFuente"
        />

        <!-- Toasts globales (vue-sonner, igual que Acadion): colores por tipo, abajo a la derecha, con botón de cerrar. -->
        <Toaster position="bottom-right" rich-colors close-button />
    </div>
</template>

<style scoped>
.shell {
    display: flex;
    min-height: 100vh;
    background: var(--color-fondo);
    color: var(--color-contenido);
    font-family: ui-sans-serif, system-ui, sans-serif;
}

/* --- Marco KIOSCO (terminal compartida, ADR-012): sin barra lateral, sólo operador + Salir + contenido. --- */
.kiosk-shell {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: var(--color-fondo);
    color: var(--color-contenido);
    font-family: ui-sans-serif, system-ui, sans-serif;
}

.kiosk-shell__bar {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 1.25rem;
    background: var(--color-barra-superior);
    color: var(--color-barra-superior-texto);
    border-bottom: 1px solid var(--color-borde);
}

.kiosk-shell__brand {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-weight: 650;
    letter-spacing: -0.01em;
}
.kiosk-shell__mark {
    display: grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    flex: none;
    border-radius: var(--radio);
    color: var(--color-acento-texto);
    background: var(--color-acento);
}
.kiosk-shell__mark svg { width: 1.2rem; height: 1.2rem; }

/* El operador, a la derecha, empujado por el margen automático. */
.kiosk-shell__op {
    margin-left: auto;
    display: flex;
    flex-direction: column;
    line-height: 1.2;
    text-align: right;
}
.kiosk-shell__op-name { font-size: 0.95rem; font-weight: 600; }
.kiosk-shell__op-role { font-size: 0.75rem; color: var(--color-suave); }

/* "Salir" afordante y grande: es un botón táctil de una caja, no un enlace de escritorio. */
.kiosk-shell__salir {
    font: inherit;
    font-weight: 600;
    padding: 0.5rem 1rem;
    border: 1px solid color-mix(in srgb, var(--color-peligro) 40%, transparent);
    border-radius: var(--radio);
    background: var(--color-peligro-tenue);
    color: var(--color-peligro);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.kiosk-shell__salir:hover { background: color-mix(in srgb, var(--color-peligro) 16%, transparent); }

.kiosk-shell__content {
    flex: 1;
    min-width: 0;
    padding: 1.25rem;
}

.sidebar {
    width: 15.5rem;
    flex: none;
    padding: 1.25rem 0.85rem;
    background: var(--color-barra-lateral);
    color: var(--color-barra-lateral-texto);
    border-right: 1px solid rgb(0 0 0 / 25%);
}

.brand {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 1.15rem;
    font-weight: 650;
    letter-spacing: -0.01em;
    color: #fff;
    text-decoration: none;
    padding: 0 0.25rem;
    margin-bottom: 1.75rem;
}

.brand__mark {
    display: grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    flex: none;
    border-radius: var(--radio);
    color: var(--color-acento-texto);
    background: var(--color-acento);
    box-shadow: 0 4px 12px -4px color-mix(in srgb, var(--color-acento) 70%, transparent);
}

.brand__mark svg {
    width: 1.2rem;
    height: 1.2rem;
}

/* Menú expandido: acordeón con un icono por sección (estilo Acadion). */
.nav { display: flex; flex-direction: column; gap: 0.15rem; }

/* Fila de nivel superior: «Inicio» (enlace) y las cabeceras de sección (botón). */
.nav-top {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    padding: 0.6rem 0.7rem;
    border: 0;
    border-radius: var(--radio);
    background: transparent;
    color: inherit;
    font: inherit;
    font-size: 0.9rem;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    transition: background-color 0.14s ease, color 0.14s ease;
}
.nav-top:hover { background: rgb(255 255 255 / 7%); color: #fff; }

.nav-top__icon { width: 1.25rem; height: 1.25rem; flex: none; }
.nav-top__label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* «Aquí estás» en el color del tema (barra_lateral_activo), no un tinte suelto. */
.nav-top--current { background: var(--color-barra-lateral-activo); color: var(--color-barra-lateral-activo-texto); font-weight: 600; }

/* La cabecera de la sección que contiene la pantalla actual: resaltada en el tono suave del tema. */
.nav-top--active { background: var(--color-barra-lateral-suave); color: #fff; }

.nav-group__chevron {
    width: 1rem;
    height: 1rem;
    flex: none;
    opacity: 0.6;
    transition: transform 0.2s ease;
}
.nav-group__chevron--open { transform: rotate(90deg); }

.nav-group__items {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    margin: 0.15rem 0 0.4rem;
    padding-left: 2.55rem;
}

.nav-subitem {
    display: block;
    padding: 0.42rem 0.6rem;
    border-radius: var(--radio-sm);
    color: color-mix(in srgb, var(--color-barra-lateral-texto) 85%, transparent);
    text-decoration: none;
    font-size: 0.85rem;
    transition: background-color 0.14s ease, color 0.14s ease;
}
.nav-subitem:hover { background: rgb(255 255 255 / 6%); color: #fff; }
.nav-subitem--current { background: var(--color-barra-lateral-activo); color: var(--color-barra-lateral-activo-texto); font-weight: 600; }

/* Cabecera: marca + botón de colapso. El margen inferior lo pone la cabecera, no la marca. */
.sidebar__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}
.brand { margin-bottom: 0; }

.collapse-toggle {
    display: grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    flex: none;
    border: none;
    border-radius: var(--radio);
    background: rgb(255 255 255 / 7%);
    color: var(--color-barra-lateral-texto);
    cursor: pointer;
    transition: background-color 0.14s ease, color 0.14s ease;
}
.collapse-toggle:hover { background: rgb(255 255 255 / 14%); color: #fff; }
.collapse-toggle svg { width: 1.1rem; height: 1.1rem; }

/* --- Modo colapsado: rail de iconos (sólo escritorio) --- */
.sidebar--collapsed { width: 4rem; padding-left: 0.55rem; padding-right: 0.55rem; }
.sidebar--collapsed .sidebar__head { flex-direction: column-reverse; gap: 0.7rem; }
.sidebar--collapsed .brand { padding: 0; }
.sidebar--collapsed .brand__name { display: none; }
.sidebar--collapsed .collapse-toggle svg { transform: rotate(180deg); }

.rail { display: flex; flex-direction: column; align-items: center; gap: 0.3rem; }
.rail-icon {
    display: grid;
    place-items: center;
    width: 2.7rem;
    height: 2.7rem;
    border: none;
    border-radius: var(--radio);
    background: transparent;
    color: inherit;
    cursor: pointer;
    transition: background-color 0.14s ease, color 0.14s ease;
}
.rail-icon svg { width: 1.4rem; height: 1.4rem; }
.rail-icon:hover { background: rgb(255 255 255 / 8%); color: #fff; }
.rail-icon--current,
.rail-icon--open {
    background: color-mix(in srgb, var(--color-acento) 26%, transparent);
    color: #fff;
}

.rail-section { position: relative; display: flex; justify-content: center; width: 100%; }

/* Flyout de la sección: flota sobre el contenido, con el color del panel del admin (no el del rail oscuro). */
.flyout {
    position: absolute;
    left: calc(100% + 0.5rem);
    top: 0;
    z-index: 40;
    min-width: 12.5rem;
    padding: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    box-shadow: var(--sombra-lg);
}
.flyout__title {
    margin: 0.15rem 0.55rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-suave);
}
.flyout__item {
    display: block;
    padding: 0.45rem 0.55rem;
    border-radius: var(--radio-sm);
    color: inherit;
    text-decoration: none;
    font-size: 0.88rem;
}
.flyout__item:hover { background: color-mix(in srgb, var(--color-acento) 12%, transparent); }
.flyout__item--current { color: var(--color-acento); font-weight: 600; }

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
    background: var(--color-barra-superior);
    color: var(--color-barra-superior-texto);
    border-bottom: 1px solid var(--color-borde);
}

.topbar__user {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 1rem;
}

/* Mismo estilo que el disparador de la campana (NotificationBell): emoji sin caja ni borde, para que la barra superior
   se vea uniforme. */
.topbar__icono {
    background: none;
    border: 0;
    cursor: pointer;
    font-size: 1.15rem;
    line-height: 1;
    padding: 0;
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
    color: var(--color-suave);
}

/* Botón afordante, no texto que finge serlo: borde y tinte de acento al pasar (la queja de Fase A). */
.link-button {
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 0.32rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    border-radius: var(--radio);
    background: transparent;
    color: var(--color-acento);
    cursor: pointer;
    transition: background-color 0.15s ease;
}

.link-button:hover {
    background: color-mix(in srgb, var(--color-acento) 10%, transparent);
}

.readonly-banner {
    padding: 0.6rem 1.5rem;
    background: var(--color-aviso-tenue);
    border-bottom: 1px solid color-mix(in srgb, var(--color-aviso) 35%, transparent);
    color: var(--color-aviso-texto);
    font-size: 0.85rem;
}

.content {
    padding: 1.5rem;
    flex: 1;
}

/* Migajas como barra-píldora, alineadas a la derecha en su PROPIA línea, arriba del contenido.
 *
 * Antes flotaban a la derecha para quedar en la misma línea que el título. El problema: un `float` hace que cualquier
 * página cuya raíz sea `grid`/`flex` (Caja, Comandas, cuentas…) se ENCOJA a su lado —un contenedor de formato evita el
 * flotante durante TODA su altura, no sólo la línea del título—, y el contenido no tomaba el ancho completo. En su
 * propia línea, el contenido de abajo ocupa todo el ancho sea grid o no. `margin-left: auto` la manda a la derecha. */
.migajas {
    display: flex;
    width: fit-content;
    max-width: 100%;
    margin: 0 0 0.6rem auto;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0.75rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    box-shadow: var(--sombra-sm);
    font-size: 0.8rem;
    line-height: 1.4;
    flex-wrap: wrap;
}

.migaja {
    color: var(--color-suave);
    text-decoration: none;
    white-space: nowrap;
}

.migaja--enlace {
    transition: color 0.15s ease;
}

.migaja--enlace:hover {
    color: var(--color-acento);
}

/* La posición actual: chip lleno del acento del tema, para que se lea de un vistazo dónde está el usuario. */
.migaja--actual {
    padding: 0.12rem 0.6rem;
    background: var(--color-acento);
    color: var(--color-acento-texto);
    border-radius: 999px;
    font-weight: 600;
}

.migaja__sep {
    color: var(--color-suave);
    opacity: 0.5;
}

/* En móvil no hay mucho que navegar y las migajas comen espacio: se ocultan. */
@media (max-width: 640px) { .migajas { display: none; } }

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

.sidebar-backdrop {
    display: none;
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

    /* Debajo del cajón y encima del contenido. */
    .sidebar-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 19;
        background: rgb(0 0 0 / 40%);
    }

    .sidebar--open {
        transform: translateX(0);
    }

    .menu-toggle {
        display: block;
    }

    /* El colapso a rail es de escritorio; en el cajón móvil no tiene sentido. */
    .collapse-toggle {
        display: none;
    }
}
</style>
