<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Tablero de la plataforma: cuántos negocios hay y en qué estado. Sólo el conteo del registro de negocios.
 */
defineProps({
    total: { type: Number, required: true },
    by_status: { type: Array, required: true },
    recent: { type: Array, required: true },
});

const BADGE = {
    active: 'ok',
    pending_activation: 'warn',
    read_only: 'warn',
    suspended: 'off',
    pending_deletion: 'off',
    cancelled: 'off',
};
</script>

<template>
    <Head title="Plataforma · Tablero" />

    <div class="tablero">
        <header class="cab">
            <h1>Tablero</h1>
            <Link href="/plataforma/negocios/nuevo" class="btn">Nuevo negocio</Link>
        </header>

        <div class="tarjeta total">
            <span>Negocios en la plataforma</span>
            <strong>{{ total }}</strong>
        </div>

        <div class="grid">
            <div v-for="s in by_status" :key="s.status" class="tarjeta">
                <span>{{ s.label }}</span>
                <strong>{{ s.total }}</strong>
            </div>
        </div>

        <section class="recientes">
            <h2>Altas recientes</h2>
            <ul v-if="recent.length" class="lista tarjeta">
                <li v-for="b in recent" :key="b.ulid">
                    <Link :href="`/plataforma/negocios/${b.ulid}`" class="nombre">{{ b.name }}</Link>
                    <span class="badge" :class="`badge--${BADGE[b.status] ?? 'off'}`">{{ b.status_label }}</span>
                    <span class="fecha">{{ b.created_at }}</span>
                </li>
            </ul>
            <p v-else class="vacio">Aún no hay negocios. Da de alta el primero.</p>
        </section>
    </div>
</template>

<style scoped>
.tablero { --plat: #4f46e5; display: grid; gap: 1.25rem; max-width: 52rem; }
.cab { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.cab h1 { margin: 0; font-size: 1.4rem; font-weight: 650; letter-spacing: -0.015em; }

.btn {
    font: inherit;
    font-size: 0.9rem;
    font-weight: 600;
    padding: 0.55rem 1.1rem;
    border-radius: 0.5rem;
    color: #fff;
    background: var(--plat);
    text-decoration: none;
    box-shadow: 0 6px 14px -6px rgb(79 70 229 / 0.6);
}
.btn:hover { filter: brightness(1.06); }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.1rem 1.25rem;
    display: grid;
    gap: 0.25rem;
}
.tarjeta span { font-size: 0.82rem; color: var(--color-suave); }
.tarjeta strong { font-size: 1.6rem; font-weight: 700; font-variant-numeric: tabular-nums; }

.total strong { color: var(--plat); }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: 0.85rem; }
.grid .tarjeta strong { font-size: 1.3rem; }

.recientes { display: grid; gap: 0.75rem; }
.recientes h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }
.lista { list-style: none; margin: 0; padding: 0.4rem 0; display: grid; }
.lista li { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 1.25rem; border-bottom: 1px solid var(--color-borde); }
.lista li:last-child { border-bottom: 0; }
.nombre { font-weight: 600; color: var(--plat); text-decoration: none; }
.nombre:hover { text-decoration: underline; }
.fecha { margin-left: auto; font-size: 0.8rem; color: var(--color-suave); font-variant-numeric: tabular-nums; }
.badge { display: inline-flex; align-items: center; padding: 0.14rem 0.55rem; border-radius: 999px; font-size: 0.74rem; font-weight: 600; }
.badge--ok { background: var(--color-exito-tenue); color: var(--color-exito); }
.badge--warn { background: var(--color-aviso-tenue); color: var(--color-aviso); }
.badge--off { background: color-mix(in srgb, var(--color-suave) 15%, transparent); color: var(--color-suave); }
.vacio { color: var(--color-suave); }
</style>
