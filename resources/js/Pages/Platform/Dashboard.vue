<script setup>
import { Head, Link } from '@inertiajs/vue3';

/**
 * Tablero de la plataforma: cuántos negocios hay y en qué estado. Sólo el conteo del registro de negocios.
 */
defineProps({
    total: { type: Number, required: true },
    by_status: { type: Array, required: true },
});
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
</style>
