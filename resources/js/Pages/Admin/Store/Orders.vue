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

    <div class="pedidos">
        <h1>Pedidos de la tienda</h1>
        <p class="nota">Acepta los pedidos pagados para que la cocina los prepare, o revísalos por estado.</p>

        <div class="filtros">
            <button v-for="s in STATUSES" :key="s.value" type="button" :class="{ activo: filter === s.value }" @click="pick(s.value)">
                {{ s.label }}
            </button>
        </div>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="loading" class="nota">Cargando…</p>
        <p v-else-if="!orders.length" class="nota">No hay pedidos en este estado.</p>

        <ul v-else class="lista">
            <li v-for="o in orders" :key="o.ulid" class="pedido">
                <div class="cabecera">
                    <strong>{{ o.folio }}</strong>
                    <span class="estado">{{ o.status_label }}</span>
                    <span class="total">${{ o.total }}</span>
                </div>
                <p class="cliente">{{ o.customer_name }} · {{ o.delivery_type === 'shipping' ? 'Envío a domicilio' : 'Recoger en sucursal' }}</p>
                <ul class="items">
                    <li v-for="(it, i) in o.items" :key="i">{{ it.quantity }} × {{ it.name }}</li>
                </ul>
                <div class="acciones">
                    <template v-if="o.status === 'paid'">
                        <button type="button" class="aceptar" :disabled="accepting === o.ulid" @click="accept(o.ulid)">Aceptar</button>
                        <button type="button" class="rechazar" :disabled="accepting === o.ulid" @click="reject(o.ulid)">Rechazar</button>
                    </template>
                    <button v-else-if="o.status === 'accepted'" type="button" class="aceptar" :disabled="accepting === o.ulid" @click="markReady(o.ulid)">Marcar listo</button>
                    <button v-else-if="o.status === 'ready'" type="button" class="aceptar" :disabled="accepting === o.ulid" @click="complete(o.ulid)">Marcar entregado</button>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.pedidos { display: grid; gap: 1rem; max-width: 46rem; }
.pedidos h1 { margin: 0; }
.nota { color: #555; font-size: 0.9rem; margin: 0; }
.error { color: #a11; }
.filtros { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.filtros button { padding: 0.35rem 0.75rem; border: 1px solid #d6d3d1; border-radius: 999px; background: #fff; cursor: pointer; font: inherit; font-size: 0.85rem; }
.filtros button.activo { background: #1c1917; color: #fff; border-color: #1c1917; }
.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.75rem; }
.pedido { border: 1px solid #e7e5e4; border-radius: 8px; padding: 0.9rem 1rem; display: grid; gap: 0.4rem; }
.cabecera { display: flex; align-items: center; gap: 0.75rem; }
.cabecera .estado { font-size: 0.8rem; color: #57534e; background: #f5f5f4; padding: 0.1rem 0.5rem; border-radius: 999px; }
.cabecera .total { margin-left: auto; font-weight: 700; }
.cliente { color: #555; font-size: 0.85rem; margin: 0; }
.items { list-style: none; margin: 0; padding: 0; font-size: 0.9rem; color: #292524; }
.acciones { display: flex; gap: 0.5rem; margin-top: 0.25rem; }
.aceptar { padding: 0.4rem 1rem; border: 0; border-radius: 6px; background: #16a34a; color: #fff; cursor: pointer; font: inherit; }
.rechazar { padding: 0.4rem 1rem; border: 1px solid #dc2626; border-radius: 6px; background: #fff; color: #dc2626; cursor: pointer; font: inherit; }
.aceptar:disabled, .rechazar:disabled { opacity: 0.6; cursor: default; }
</style>
