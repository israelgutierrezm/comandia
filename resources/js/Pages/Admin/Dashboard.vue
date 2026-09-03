<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../../components/Icon.vue';

/**
 * Inicio de la administración.
 *
 * Deliberadamente **sin métricas**: los tableros con indicadores se construyen sobre el motor de reportes (ADR-006) y no
 * existen todavía. Inventar aquí un par de contadores sueltos crearía una segunda vía de agregación que después habría
 * que desmontar, justo lo que ADR-006 quiere evitar.
 *
 * Lo que sí orienta: qué negocio y sucursal están activos, qué falta por configurar, y ACCESOS RÁPIDOS a lo más usado —
 * filtrados por el permiso del rol activo (y por módulo contratado), como la navegación. No es una segunda fuente de
 * datos: son enlaces.
 */
const page = usePage();
const { can, hasModule } = useAuthorization();

const context = computed(() => page.props.context);

const pendientes = computed(() => {
    const items = [];

    if (!context.value?.branch_ulid) {
        items.push({
            text: 'Selecciona una sucursal en la barra superior para operar.',
            route: null,
        });
    }

    if (!context.value?.membership?.employee_code && can('identity.users.view')) {
        // Sin código de empleado no se puede autorizar por PIN (D84), y descubrirlo con el cliente delante es peor que
        // avisarlo aquí.
        items.push({
            text: 'Tu cuenta no tiene código de empleado: sin él no podrás autorizar acciones con PIN.',
            route: '/admin/personal',
        });
    }

    return items;
});

/**
 * Accesos rápidos a lo más usado. Mismos permisos y módulos que la navegación (un enlace de más nunca deja pasar nada:
 * el servidor decide). Las URLs son las mismas de la tabla del shell.
 */
const accesos = computed(() => [
    { label: 'Punto de venta', hint: 'Cobra y toma pedidos', url: '/admin/pos/cuentas', icon: 'receipt', permission: 'pos.orders.create' },
    { label: 'Artículos', hint: 'Tu menú y catálogo', url: '/admin/articulos', icon: 'box', permission: 'catalog.articles.view' },
    { label: 'Existencias', hint: 'Inventario al día', url: '/admin/existencias', icon: 'truck', permission: 'inventory.stock.view' },
    { label: 'Recepciones', hint: 'Entradas de compra', url: '/admin/recepciones', icon: 'receive', permission: 'purchasing.receipts.create' },
    { label: 'Reportes', hint: 'Ventas y finanzas', url: '/admin/reportes', icon: 'chart', permission: 'finance.journal.view' },
    { label: 'Tienda en línea', hint: 'Configura tu tienda', url: '/admin/tienda', icon: 'shop', permission: 'ecommerce.store.configure', module: 'Ecommerce' },
    { label: 'Personal', hint: 'Equipo y accesos', url: '/admin/personal', icon: 'users', permission: 'identity.users.view' },
    { label: 'Configuración', hint: 'Ajustes del negocio', url: '/admin/configuracion', icon: 'key', permission: 'configuration.tenant.view' },
].filter((a) => can(a.permission) && (!a.module || hasModule(a.module))));
</script>

<template>
    <Head title="Inicio" />

    <!-- Bienvenida: qué negocio, con qué rol y en qué sucursal. -->
    <section class="hero animar-entrada">
        <p class="hero__eyebrow">Bienvenido</p>
        <h1 class="hero__title">{{ context?.tenant?.name }}</h1>
        <p class="hero__meta">
            Operando como <strong>{{ context?.role_name ?? 'sin rol activo' }}</strong>
            <template v-if="context?.branch_name"> · <strong>{{ context.branch_name }}</strong></template>
        </p>
    </section>

    <!-- Lo que falta para operar. -->
    <section v-if="pendientes.length" class="pendientes animar-entrada">
        <h2 class="pendientes__titulo">
            <Icon name="dot" :size="16" /> Para terminar de configurar
        </h2>
        <ul class="pendientes__lista">
            <li v-for="(item, index) in pendientes" :key="index">
                <Link v-if="item.route" :href="item.route">{{ item.text }}</Link>
                <span v-else>{{ item.text }}</span>
            </li>
        </ul>
    </section>

    <!-- Accesos rápidos. -->
    <section v-if="accesos.length" class="animar-entrada">
        <h2 class="seccion__titulo">Accesos rápidos</h2>
        <div class="accesos">
            <Link v-for="a in accesos" :key="a.url" :href="a.url" class="acceso">
                <span class="acceso__icon"><Icon :name="a.icon" :size="20" /></span>
                <span class="acceso__txt">
                    <span class="acceso__label">{{ a.label }}</span>
                    <span class="acceso__hint">{{ a.hint }}</span>
                </span>
                <Icon name="chevron" :size="16" class="acceso__go" />
            </Link>
        </div>
    </section>

    <p class="indicadores-nota">
        Los tableros con métricas llegan con el motor de reportes — se construyen sobre él a propósito, para no tener
        contadores paralelos que después haya que desmontar.
    </p>
</template>

<style scoped>
@import '../../../css/admin-page.css';

/* Hero de bienvenida: banda con un tinte del acento del negocio. */
.hero {
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--color-acento) 12%, var(--color-superficie)),
        var(--color-superficie) 70%);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    padding: 1.6rem 1.5rem;
    margin-bottom: 1.25rem;
}
.hero__eyebrow {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-acento);
}
.hero__title {
    margin: 0.25rem 0 0;
    font-size: clamp(1.5rem, 3.5vw, 2rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.1;
    text-wrap: balance;
}
.hero__meta { margin: 0.45rem 0 0; color: var(--color-suave); font-size: 0.92rem; }
.hero__meta strong { color: var(--color-contenido); font-weight: 600; }

/* Pendientes de configuración: aviso, no error. */
.pendientes {
    background: var(--color-aviso-tenue);
    border: 1px solid color-mix(in srgb, var(--color-aviso) 35%, transparent);
    border-radius: var(--radio);
    padding: 1rem 1.15rem;
    margin-bottom: 1.5rem;
    max-width: 46rem;
}
.pendientes__titulo {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0 0 0.5rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--color-aviso);
}
.pendientes__lista {
    margin: 0;
    padding-left: 1.4rem;
    font-size: 0.9rem;
    display: grid;
    gap: 0.35rem;
    color: var(--color-aviso);
}
.pendientes__lista a { color: inherit; font-weight: 600; }

/* Sección de accesos rápidos. */
.seccion__titulo {
    margin: 0 0 0.9rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--color-suave);
}
.accesos {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
    gap: 0.85rem;
}
.acceso {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.9rem 1rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    box-shadow: var(--sombra-sm);
    text-decoration: none;
    color: var(--color-contenido);
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.acceso:hover {
    border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde));
    box-shadow: var(--sombra);
    transform: translateY(-2px);
}
.acceso__icon {
    flex: none;
    width: 2.6rem;
    height: 2.6rem;
    display: grid;
    place-items: center;
    border-radius: var(--radio-sm);
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    color: var(--color-acento);
}
.acceso__txt { display: flex; flex-direction: column; min-width: 0; }
.acceso__label { font-weight: 600; font-size: 0.95rem; }
.acceso__hint { font-size: 0.8rem; color: var(--color-suave); }
.acceso__go { margin-left: auto; color: var(--color-suave); flex: none; }
.acceso:hover .acceso__go { color: var(--color-acento); }

.indicadores-nota {
    margin: 1.75rem 0 0;
    max-width: 46rem;
    font-size: 0.85rem;
    color: var(--color-suave);
    line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
    .acceso:hover { transform: none; }
}
</style>
