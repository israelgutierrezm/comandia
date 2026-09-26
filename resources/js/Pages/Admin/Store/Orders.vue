<script setup>
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Bandeja de aceptación de pedidos de la tienda (Iteración 8, Tanda D). El personal ve los pedidos pagados y los acepta
 * para que la cocina los prepare (ahí se descuenta el inventario y se generan las comandas). Sólo aparece con el módulo
 * Ecommerce y el permiso `ecommerce.orders.view`.
 */
const STATUSES = [
    { value: 'paid', label: 'Por aceptar' },
    { value: 'accepted', label: 'Aceptados' },
    { value: 'ready', label: 'Listos' },
    { value: 'packed', label: 'Empacados' },
    { value: 'shipped', label: 'Enviados' },
    { value: 'completed', label: 'Completados' },
    { value: 'rejected', label: 'Rechazados' },
];

// La pastilla de estado toma el color del sistema compartido: ámbar = pide atención (por aceptar),
// verde = en marcha (aceptado/listo/empacado/enviado), gris = cerrado (completado/rechazado).
const BADGES = {
    paid: 'badge--warn',
    accepted: 'badge--ok',
    ready: 'badge--ok',
    packed: 'badge--ok',
    shipped: 'badge--ok',
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
const markPacked = (ulid) => act(ulid, 'pack');
const complete = (ulid) => act(ulid, 'complete');

function ship(ulid) {
    // Paquetería y guía son opcionales (el servidor las acepta vacías), pero «Cancelar» en cualquiera de los dos
    // prompts ABORTA: antes el pedido se marcaba enviado igual.
    const carrier = window.prompt('Paquetería (opcional, p. ej. Estafeta):');
    if (carrier === null) return;

    const trackingNumber = window.prompt('Número de guía (opcional):');
    if (trackingNumber === null) return;

    act(ulid, 'ship', { carrier: carrier.trim(), tracking_number: trackingNumber.trim() });
}

function reject(ulid) {
    // Rechazar REEMBOLSA: «Cancelar» aborta y el motivo es obligatorio (el servidor también lo exige). Antes
    // «Cancelar» mandaba vacío y el pedido se rechazaba y reembolsaba igual.
    const reason = window.prompt('Motivo del rechazo (se reembolsa al cliente):');
    if (reason === null) return;

    if (reason.trim() === '') {
        error.value = 'Escribe el motivo del rechazo: queda en el pedido y se le explica al cliente.';
        return;
    }

    act(ulid, 'reject', { reason: reason.trim() });
}

onMounted(load);
</script>

<template>
    <Head title="Pedidos" />

    <div class="pedidos animar-entrada">
        <ListHeader
            title="Pedidos de la tienda"
            subtitle="Acepta los pedidos pagados y dales seguimiento hasta la entrega, o revísalos por estado."
            :count="orders.length"
        />

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
        <template v-if="loading"></template>
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
                <p v-if="o.tracking_number || o.carrier" class="guia">
                    <Icon name="truck" /> {{ o.carrier || 'Paquetería' }}<template v-if="o.tracking_number"> · guía {{ o.tracking_number }}</template>
                </p>
                <div class="row-actions">
                    <template v-if="o.status === 'paid'">
                        <button type="button" class="button" :disabled="accepting === o.ulid" @click="accept(o.ulid)"><Icon name="check" /> Aceptar</button>
                        <button type="button" class="button button--danger" :disabled="accepting === o.ulid" @click="reject(o.ulid)"><Icon name="x" /> Rechazar</button>
                    </template>
                    <template v-else-if="o.status === 'accepted'">
                        <!-- El modo de la tienda decide el camino: envío empaca; preparación manda listo a entregar. -->
                        <button v-if="o.fulfillment_mode === 'dispatch'" type="button" class="button" :disabled="accepting === o.ulid" @click="markPacked(o.ulid)"><Icon name="box" /> Empacar</button>
                        <button v-else type="button" class="button" :disabled="accepting === o.ulid" @click="markReady(o.ulid)"><Icon name="check" /> Marcar listo</button>
                    </template>
                    <button v-else-if="o.status === 'packed'" type="button" class="button" :disabled="accepting === o.ulid" @click="ship(o.ulid)"><Icon name="truck" /> Marcar enviado</button>
                    <button v-else-if="o.status === 'ready' || o.status === 'shipped'" type="button" class="button" :disabled="accepting === o.ulid" @click="complete(o.ulid)"><Icon name="check" /> Marcar entregado</button>
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
}

/* Los segmentos de filtro (`.filtros`/`.filtro`) vienen de admin-page.css. */

.lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(22rem, 1fr));
    gap: 0.75rem;
    align-items: start;
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

/* Paquetería y guía del pedido enviado: dato tenue, con el icono de camión a juego. */
.guia {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    font-size: 0.82rem;
    color: var(--color-suave);
    font-variant-numeric: tabular-nums;
}

.row-actions {
    margin-top: 0.25rem;
}
</style>
