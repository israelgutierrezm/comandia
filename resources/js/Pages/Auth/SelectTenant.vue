<script setup>
import { Head, router } from '@inertiajs/vue3';

/**
 * Selección de negocio (§2).
 *
 * Aparece cuando la persona pertenece a más de un negocio, y también cuando quiere cambiar de uno a
 * otro sin cerrar sesión — el caso de quien administra dos restaurantes: la identidad es global y la
 * pertenencia es por tenant (§4.1).
 */
defineProps({
    tenants: { type: Array, required: true },
});

function enter(ulid) {
    router.post('/negocios', { tenant_ulid: ulid });
}
</script>

<template>
    <Head title="Elige un negocio" />

    <main class="page">
        <div class="card">
            <h1>Elige un negocio</h1>
            <p class="lead">Tu cuenta tiene acceso a más de uno.</p>

            <ul class="list">
                <li v-for="tenant in tenants" :key="tenant.ulid">
                    <button class="option" type="button" @click="enter(tenant.ulid)">
                        <span class="option__name">{{ tenant.name }}</span>
                        <span class="option__meta">
                            {{ tenant.display_name }}
                            <!--
                                Se avisa antes de entrar: un negocio en sólo lectura por impago
                                conserva sus datos y no puede operar, y enterarse al intentar
                                guardar sería peor.
                            -->
                            <template v-if="tenant.is_read_only">
                                · <strong>sólo lectura</strong>
                            </template>
                        </span>
                    </button>
                </li>
            </ul>

            <button class="link-button" type="button" @click="router.post('/logout')">
                Salir de mi cuenta
            </button>
        </div>
    </main>
</template>

<style scoped>
.page {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background: #f8f7f5;
    font-family: ui-sans-serif, system-ui, sans-serif;
    color: #1c1917;
}

.card {
    width: 100%;
    max-width: 26rem;
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 0.75rem;
    padding: 2rem;
}

h1 {
    margin: 0;
    font-size: 1.35rem;
    font-weight: 600;
}

.lead {
    margin: 0.25rem 0 1.5rem;
    font-size: 0.9rem;
    opacity: 0.6;
}

.list {
    list-style: none;
    margin: 0 0 1.25rem;
    padding: 0;
    display: grid;
    gap: 0.5rem;
}

.option {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.15rem;
    padding: 0.75rem 0.9rem;
    border: 1px solid #d6d3d1;
    border-radius: 0.5rem;
    background: #fff;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.option:hover {
    border-color: #c2410c;
    background: #fff7ed;
}

.option__name {
    font-weight: 600;
}

.option__meta {
    font-size: 0.8rem;
    opacity: 0.65;
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
</style>
