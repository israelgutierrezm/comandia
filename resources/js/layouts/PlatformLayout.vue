<script setup>
import { computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

/**
 * Shell de la SUPER ADMINISTRACIÓN de la plataforma.
 *
 * Aparte del shell de los negocios (`AdminLayout`): aquí no hay negocio, ni sucursal, ni rol activo. Marca y acento
 * propios (fríos, no la terracota de los negocios) para que sea evidente de un vistazo que se está en la plataforma y
 * no dentro de un negocio.
 */
const page = usePage();
const admin = computed(() => page.props.platform_auth);
const flash = computed(() => page.props.flash ?? {});

const nav = [
    { label: 'Tablero', href: '/plataforma', match: /^\/plataforma\/?$/ },
    { label: 'Negocios', href: '/plataforma/negocios', match: /^\/plataforma\/negocios/ },
];

const path = computed(() => page.url.split('?')[0]);

function logout() {
    router.post('/plataforma/salir');
}
</script>

<template>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <span class="brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                    </svg>
                </span>
                <span class="brand__text">
                    Comandia
                    <small>Plataforma</small>
                </span>
            </div>

            <nav>
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="nav-item"
                    :class="{ 'nav-item--current': item.match.test(path) }"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <div class="main">
            <header class="topbar">
                <div class="topbar__id">
                    <span class="topbar__name">{{ admin?.name }}</span>
                    <span class="topbar__email">{{ admin?.email }}</span>
                </div>
                <button type="button" class="salir" @click="logout">Salir</button>
            </header>

            <p v-if="flash.success" class="flash flash--ok">{{ flash.success }}</p>
            <p v-if="flash.error" class="flash flash--err">{{ flash.error }}</p>

            <main class="content">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
/* Acento FIJO de plataforma (índigo frío), distinto de la terracota de los negocios. */
.shell {
    --plat: #4f46e5;
    --plat-texto: #ffffff;
    --plat-lateral: #1e293b;
    --plat-lateral-texto: #cbd5e1;
    display: flex;
    min-height: 100vh;
    background: var(--color-fondo);
    color: var(--color-contenido);
    font-family: ui-sans-serif, system-ui, sans-serif;
}

.sidebar {
    width: 15.5rem;
    flex: none;
    padding: 1.25rem 0.85rem;
    background: var(--plat-lateral);
    color: var(--plat-lateral-texto);
}

.brand {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0 0.25rem;
    margin-bottom: 1.75rem;
    color: #fff;
}

.brand__mark {
    display: grid;
    place-items: center;
    width: 1.9rem;
    height: 1.9rem;
    flex: none;
    border-radius: 0.55rem;
    color: var(--plat-texto);
    background: var(--plat);
}

.brand__mark svg { width: 1.2rem; height: 1.2rem; }

.brand__text {
    display: flex;
    flex-direction: column;
    font-size: 1.1rem;
    font-weight: 650;
    letter-spacing: -0.01em;
    line-height: 1.1;
}

.brand__text small {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: color-mix(in srgb, var(--plat-lateral-texto) 70%, transparent);
}

.nav-item {
    display: block;
    padding: 0.5rem 0.6rem;
    border-radius: 0.5rem;
    color: inherit;
    text-decoration: none;
    font-size: 0.9rem;
    transition: background-color 0.14s ease, color 0.14s ease;
}

.nav-item:hover { background: rgb(255 255 255 / 7%); color: #fff; }

.nav-item--current {
    background: color-mix(in srgb, var(--plat) 30%, transparent);
    color: #fff;
    font-weight: 600;
    box-shadow: inset 3px 0 0 var(--plat);
}

.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

.topbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 1rem;
    padding: 0.75rem 1.5rem;
    background: var(--color-superficie);
    border-bottom: 1px solid var(--color-borde);
}

.topbar__id { display: flex; flex-direction: column; text-align: right; line-height: 1.2; }
.topbar__name { font-size: 0.9rem; font-weight: 600; }
.topbar__email { font-size: 0.75rem; color: var(--color-suave); }

.salir {
    font: inherit;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 0.32rem 0.7rem;
    border: 1px solid color-mix(in srgb, var(--plat) 40%, transparent);
    border-radius: 0.5rem;
    background: transparent;
    color: var(--plat);
    cursor: pointer;
    transition: background-color 0.15s ease;
}
.salir:hover { background: color-mix(in srgb, var(--plat) 10%, transparent); }

.flash { margin: 1rem 1.5rem 0; padding: 0.7rem 0.95rem; border-radius: 0.6rem; font-size: 0.9rem; }
.flash--ok { background: var(--color-exito-tenue); color: #14532d; }
.flash--err { background: var(--color-peligro-tenue); color: #7f1d1d; }

.content { padding: 1.5rem; flex: 1; }
</style>
