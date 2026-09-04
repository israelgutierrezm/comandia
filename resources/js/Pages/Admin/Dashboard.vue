<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../../components/Icon.vue';

/**
 * Inicio de la administración.
 *
 * Sigue **sin métricas agregadas**: los tableros con indicadores (ventas del día, comparativas «vs ayer») se construyen
 * sobre el motor de reportes (ADR-006) y no existen todavía. Inventar aquí un par de contadores sueltos crearía una
 * segunda vía de agregación que después habría que desmontar, justo lo que ADR-006 quiere evitar. El «Resumen de hoy»
 * llega cuando llegue ese motor (o un endpoint de estado vivo aprobado): entra ARRIBA de los accesos, sin tocar lo demás.
 *
 * Lo que sí orienta hoy: qué negocio, rol y sucursal están activos (del shell, D59), qué falta por configurar, y ACCESOS
 * RÁPIDOS a lo más usado —filtrados por el permiso del rol activo y por módulo contratado, como la navegación—. No es una
 * segunda fuente de datos: son enlaces con color por categoría para encontrarlos de un vistazo.
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
 * el servidor decide). El `tint` es color por categoría —una ayuda para ubicar, no semántica de estado—.
 */
const accesos = computed(() => [
    { label: 'Punto de venta', hint: 'Cobra y toma pedidos', url: '/admin/pos/cuentas', icon: 'receipt', tint: 'verde', permission: 'pos.orders.create' },
    { label: 'Artículos', hint: 'Tu menú y catálogo', url: '/admin/articulos', icon: 'box', tint: 'azul', permission: 'catalog.articles.view' },
    { label: 'Existencias', hint: 'Inventario al día', url: '/admin/existencias', icon: 'truck', tint: 'verde', permission: 'inventory.stock.view' },
    { label: 'Recepciones', hint: 'Entradas de compra', url: '/admin/recepciones', icon: 'receive', tint: 'violeta', permission: 'purchasing.receipts.create' },
    { label: 'Reportes', hint: 'Ventas y finanzas', url: '/admin/reportes', icon: 'chart', tint: 'ambar', permission: 'finance.journal.view' },
    { label: 'Tienda en línea', hint: 'Configura tu tienda', url: '/admin/tienda', icon: 'shop', tint: 'rojo', permission: 'ecommerce.store.configure', module: 'Ecommerce' },
    { label: 'Personal', hint: 'Equipo y accesos', url: '/admin/personal', icon: 'users', tint: 'aqua', permission: 'identity.users.view' },
    { label: 'Configuración', hint: 'Ajustes del negocio', url: '/admin/configuracion', icon: 'key', tint: 'gris', permission: 'configuration.tenant.view' },
].filter((a) => can(a.permission) && (!a.module || hasModule(a.module))));
</script>

<template>
    <Head title="Inicio" />

    <!-- Bienvenida: qué negocio, con qué rol y en qué sucursal (todo del shell, D59). -->
    <section class="hero animar-entrada">
        <div class="hero__cuerpo">
            <p class="hero__eyebrow">Bienvenido</p>
            <h1 class="hero__title">{{ context?.tenant?.name }}</h1>

            <div class="hero__chips">
                <div v-if="context?.branch_name" class="chip">
                    <span class="chip__icon"><Icon name="shop" :size="18" /></span>
                    <span class="chip__txt">
                        <span class="chip__label">Sucursal activa</span>
                        <span class="chip__value">{{ context.branch_name }}</span>
                    </span>
                </div>
                <div class="chip">
                    <span class="chip__icon"><Icon name="key" :size="18" /></span>
                    <span class="chip__txt">
                        <span class="chip__label">Operando como</span>
                        <span class="chip__value">{{ context?.role_name ?? 'sin rol activo' }}</span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Ilustración decorativa; se recorta con el borde del hero. Puro adorno, sin datos. -->
        <div class="hero__arte" aria-hidden="true">
            <svg viewBox="0 0 320 180" fill="none" xmlns="http://www.w3.org/2000/svg">
                <g class="arte-nube">
                    <path d="M232 44c0-10 8-18 18-18 7 0 14 4 16 11 9 0 16 7 16 16s-7 16-16 16h-34c-8 0-15-6-15-14 0-7 6-13 13-13 1 0 1 0 2 2z" />
                    <path d="M150 30c0-7 6-13 13-13 5 0 10 3 12 8 6 0 11 5 11 11s-5 11-11 11h-25c-6 0-11-4-11-10 0-5 4-9 9-9z" />
                </g>
                <g class="arte-tienda">
                    <!-- fachada -->
                    <rect x="196" y="92" width="104" height="78" rx="4" />
                    <!-- toldo festoneado -->
                    <path d="M188 92h120l-8-22H196z" class="arte-toldo" />
                    <path d="M196 70l8 22M212 70l4 22M228 70l2 22M244 70v22M260 70l-2 22M276 70l-4 22M292 70l-8 22" class="arte-toldo-lineas" />
                    <!-- puerta y ventana -->
                    <rect x="212" y="128" width="30" height="42" rx="2" />
                    <rect x="258" y="128" width="30" height="26" rx="2" />
                    <line x1="273" y1="128" x2="273" y2="154" />
                    <line x1="258" y1="141" x2="288" y2="141" />
                </g>
            </svg>
        </div>
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
            <Link v-for="a in accesos" :key="a.url" :href="a.url" class="acceso" :class="`acceso--${a.tint}`">
                <span class="acceso__icon"><Icon :name="a.icon" :size="22" /></span>
                <span class="acceso__txt">
                    <span class="acceso__label">{{ a.label }}</span>
                    <span class="acceso__hint">{{ a.hint }}</span>
                </span>
                <Icon name="chevron" :size="16" class="acceso__go" />
            </Link>
        </div>
    </section>

    <p class="indicadores-nota">
        El «Resumen de hoy» (ventas, cuentas, existencias) llega con el motor de reportes — se construye sobre él a
        propósito, para no tener contadores paralelos que después haya que desmontar.
    </p>
</template>

<style scoped>
@import '../../../css/admin-page.css';

/* Hero de bienvenida: banda con un tinte del acento del negocio + ilustración recortada a la derecha. */
.hero {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 1.5rem;
    min-height: 9.5rem;
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--color-acento) 14%, var(--color-superficie)),
        var(--color-superficie) 72%);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    padding: 1.6rem 1.75rem;
    margin-bottom: 1.25rem;
}
.hero__cuerpo { position: relative; z-index: 1; }
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

/* Chips de contexto: mismo lenguaje visual que la imagen (icono redondo + etiqueta y valor). */
.hero__chips { display: flex; flex-wrap: wrap; gap: 0.9rem 1.75rem; margin-top: 1rem; }
.chip { display: flex; align-items: center; gap: 0.6rem; }
.chip__icon {
    flex: none;
    width: 2.4rem;
    height: 2.4rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: color-mix(in srgb, var(--color-acento) 12%, var(--color-superficie));
    border: 1px solid color-mix(in srgb, var(--color-acento) 25%, transparent);
    color: var(--color-acento);
}
.chip__txt { display: flex; flex-direction: column; line-height: 1.25; }
.chip__label { font-size: 0.72rem; color: var(--color-suave); }
.chip__value { font-weight: 650; font-size: 0.95rem; }

.hero__arte {
    position: absolute;
    right: 0;
    bottom: 0;
    width: min(38%, 20rem);
    max-width: 20rem;
    pointer-events: none;
}
.hero__arte svg { width: 100%; height: auto; display: block; }
.arte-tienda { stroke: color-mix(in srgb, var(--color-acento) 55%, transparent); stroke-width: 2; fill: none; }
.arte-toldo { fill: color-mix(in srgb, var(--color-acento) 14%, transparent); }
.arte-toldo-lineas { stroke: color-mix(in srgb, var(--color-acento) 40%, transparent); stroke-width: 1.5; }
.arte-nube { stroke: color-mix(in srgb, var(--color-suave) 35%, transparent); stroke-width: 2; fill: none; }

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
    grid-template-columns: repeat(auto-fill, minmax(13.5rem, 1fr));
    gap: 0.85rem;
}

/* Azulejos con color por categoría: cada tarjeta hereda su acento por `--tint`. Es coloración de categoría
   (encontrar de un vistazo), no semántica de estado; por eso vive aquí y no en los tokens de tema. */
.acceso {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 0.95rem 1rem;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    box-shadow: var(--sombra-sm);
    text-decoration: none;
    color: var(--color-contenido);
    transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.acceso:hover {
    border-color: color-mix(in srgb, var(--tint) 55%, var(--color-borde));
    box-shadow: var(--sombra);
    transform: translateY(-2px);
}
.acceso__icon {
    flex: none;
    width: 2.9rem;
    height: 2.9rem;
    display: grid;
    place-items: center;
    border-radius: var(--radio-sm);
    background: color-mix(in srgb, var(--tint) 14%, transparent);
    color: var(--tint);
}
.acceso__txt { display: flex; flex-direction: column; min-width: 0; }
.acceso__label { font-weight: 650; font-size: 0.95rem; }
.acceso__hint { font-size: 0.8rem; color: var(--color-suave); }
.acceso__go { margin-left: auto; color: var(--color-suave); flex: none; }
.acceso:hover .acceso__go { color: var(--tint); }

/* Paleta de categoría. Reusa tokens de tema donde los hay; violeta es un acento de categoría explícito. */
.acceso--verde { --tint: var(--color-exito); }
.acceso--azul { --tint: var(--color-acento); }
.acceso--violeta { --tint: #7c3aed; }
.acceso--ambar { --tint: var(--color-aviso); }
.acceso--rojo { --tint: var(--color-peligro); }
.acceso--aqua { --tint: var(--color-espera-fresca); }
.acceso--gris { --tint: var(--color-suave); }

.indicadores-nota {
    margin: 1.75rem 0 0;
    max-width: 46rem;
    font-size: 0.85rem;
    color: var(--color-suave);
    line-height: 1.5;
}

@media (max-width: 640px) {
    .hero__arte { opacity: 0.5; }
}
@media (prefers-reduced-motion: reduce) {
    .acceso:hover { transform: none; }
}
</style>
