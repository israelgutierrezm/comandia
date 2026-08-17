<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    laravelVersion: String,
    phpVersion: String,
});
</script>

<template>
    <Head title="Fundación" />

    <main class="shell">
        <h1>Comandia</h1>
        <p class="lead">
            SaaS de administración y punto de venta para negocios de alimentos y bebidas.
        </p>

        <dl class="stack">
            <div><dt>Fase</dt><dd>0 — Fundación del proyecto</dd></div>
            <div><dt>Laravel</dt><dd>{{ laravelVersion }}</dd></div>
            <div><dt>PHP</dt><dd>{{ phpVersion }}</dd></div>
            <div><dt>Frontend</dt><dd>Vue 3 + Inertia sobre Vite</dd></div>
        </dl>

        <p class="note">
            Sin tablas de dominio, sin modelos de negocio, sin módulos funcionales.
            La primera migración de dominio se escribe en la Iteración 1 — Shared Kernel,
            y sólo con el diseño aprobado.
        </p>
    </main>
</template>

<style scoped>
.shell {
    max-width: 42rem;
    margin: 0 auto;
    padding: 4rem 1.5rem;
    font-family: ui-sans-serif, system-ui, sans-serif;
    line-height: 1.6;
}

h1 {
    font-size: 2.25rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    margin: 0 0 0.5rem;
}

.lead {
    margin: 0 0 2.5rem;
    opacity: 0.7;
}

.stack > div {
    display: flex;
    gap: 1rem;
    padding: 0.6rem 0;
    border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);
}

dt {
    width: 9rem;
    flex: none;
    opacity: 0.6;
}

dd {
    margin: 0;
    font-variant-numeric: tabular-nums;
}

.note {
    margin-top: 2.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);
    font-size: 0.9rem;
    opacity: 0.6;
}
</style>
