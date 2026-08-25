<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';

/**
 * Bandeja de aceptación de pedidos de la tienda (Iteración 8, Tanda D). El personal ve los pedidos pagados y los acepta
 * para que la cocina los prepare (ahí se descuenta el inventario y se generan las comandas). Sólo aparece con el módulo
 * Ecommerce y el permiso `ecommerce.orders.view`.
 */
const STATUSES = [
    { value: 'paid', label: 'Por aceptar' },
    { value: 'accepted', label: 'Aceptados' },
    { value: 'ready', label: 'Listos' },
    { value: 'completed', label: 'Completados' },
    { value: 'rejected', label: 'Rechazados' },
];

// La pastilla de estado toma el color del sistema compartido: ámbar = pide atención (por aceptar),
// verde = en marcha (aceptado/listo), gris = cerrado (completado/rechazado).
const BADGES = {
    paid: 'badge--warn',
    accepted: 'badge--ok',
    ready: 'badge--ok',
    completed: 'badge--off',
    rejected: 'badge--off',
};

const filter = ref('paid');
const orders = ref([]);
const error = ref(null);
const loading = ref(false);
const accepting = ref('');

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get('/orders', { status: filter.value });
        orders.value = data;
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        loading.value = false;
    }
}

function pick(status) {
    filter.value = status;
    load();
}

async function act(ulid, path, body) {
    accepting.value = ulid;
    error.value = null;
    try {
        await api.post(`/orders/${ulid}/${path}`, body);
        await load();
    } catch (e) {
        if (e instanceof ApiError) error.value = e.title; else throw e;
    } finally {
        accepting.value = '';
    }
}

const accept = (ulid) => act(ulid, 'accept');
const markReady = (ulid) => act(ulid, 'ready');
const complete = (ulid) => act(ulid, 'complete');

function reject(ulid) {
    const reason = (window.prompt('Motivo del rechazo (se reembolsa al cliente):') ?? '').trim();
    act(ulid, 'reject', { reason });
}

onMounted(load);
</script>

<template>
    <Head title="Pedidos" />

    <div class="pedidos animar-entrada">
        <header class="page-header">
            <div>
                <h1>Pedidos de la tienda</h1>
                <p class="page-header__hint">Acepta los pedidos pagados para que la cocina los prepare, o revísalos por estado.</p>
            </div>
        </header>

        <div class="filtros">
            <button
                v-for="s in STATUSES"
                :key="s.value"
                type="button"
                class="filtro"
                :class="{ 'filtro--activo': filter === s.value }"
                @click="pick(s.value)"
            >
                {{ s.label }}
            </button>
        </div>

        <p v-if="error" class="alert" role="alert">{{ error }}</p>
        <p v-if="loading" class="page-header__hint">Cargando…</p>
        <p v-else-if="!orders.length" class="page-header__hint">No hay pedidos en este estado.</p>

        <ul v-else class="lista">
            <li v-for="o in orders" :key="o.ulid" class="tarjeta pedido">
                <div class="cabecera">
                    <strong class="folio">{{ o.folio }}</strong>
                    <span class="badge" :class="BADGES[o.status] ?? 'badge--off'">{{ o.status_label }}</span>
                    <span class="total">${{ o.total }}</span>
                </div>
                <p class="cliente">{{ o.customer_name }} · {{ o.delivery_type === 'shipping' ? 'Envío a domicilio' : 'Recoger en sucursal' }}</p>
                <ul class="items">
                    <li v-for="(it, i) in o.items" :key="i">{{ it.quantity }} × {{ it.name }}</li>
                </ul>
                <div class="row-actions">
                    <template v-if="o.status === 'paid'">
                        <button type="button" class="button" :disabled="accepting === o.ulid" @click="accept(o.ulid)">Aceptar</button>
                        <button type="button" class="button button--danger" :disabled="accepting === o.ulid" @click="reject(o.ulid)">Rechazar</button>
                    </template>
                    <button v-else-if="o.status === 'accepted'" type="button" class="button" :disabled="accepting === o.ulid" @click="markReady(o.ulid)">Marcar listo</button>
                    <button v-else-if="o.status === 'ready'" type="button" class="button" :disabled="accepting === o.ulid" @click="complete(o.ulid)">Marcar entregado</button>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.pedidos {
    display: grid;
    gap: 1rem;
    max-width: 46rem;
}

.filtros {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}

/* Segmentos de filtro: pastillas con borde; la activa se rellena con el acento del negocio. */
.filtro {
    font: inherit;
    font-size: 0.85rem;
    padding: 0.35rem 0.85rem;
    border: 1px solid var(--color-borde);
    border-radius: 999px;
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.filtro:hover:not(.filtro--activo) {
    border-color: color-mix(in srgb, var(--color-acento) 45%, transparent);
}

.filtro--activo {
    background: var(--color-acento);
    color: var(--color-acento-texto);
    border-color: var(--color-acento);
}

.lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.75rem;
}

.pedido {
    display: grid;
    gap: 0.45rem;
    padding: 0.95rem 1.1rem;
}

.cabecera {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.folio {
    font-size: 0.95rem;
}

.total {
    margin-left: auto;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.cliente {
    color: var(--color-suave);
    font-size: 0.85rem;
    margin: 0;
}

.items {
    list-style: none;
    margin: 0;
    padding: 0;
    font-size: 0.9rem;
    color: var(--color-contenido);
}

.row-actions {
    margin-top: 0.25rem;
}
</style>
