<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { api } from '../../../api/client';
import { useResourceList, useApiForm } from '../../../stores/useResourceList';
import DataTable from '../../../components/DataTable.vue';
import ResourceGrid from '../../../components/ResourceGrid.vue';
import ViewToggle from '../../../components/ViewToggle.vue';
import Paginacion from '../../../components/Paginacion.vue';
import ListHeader from '../../../components/ListHeader.vue';

/**
 * Clientes (§6.6).
 *
 * ## Alta express: sólo el nombre (D43)
 *
 * Todo lo demás es opcional. El caso normal es registrar a alguien que está pagando, en dos toques; pedir más haría que
 * nadie registrara clientes, y sin clientes el crédito y la factura no existen. El expediente completo —perfiles
 * fiscales, direcciones, crédito— se llena en la ficha del cliente, cuando hace falta.
 */
const list = useResourceList('/customers', { initialFilters: { status: '', with_debt: '' } });

const filtrosActivos = computed(
    () => [list.filters.status !== '', list.filters.with_debt === '1'].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.status = '';
    list.filters.with_debt = '';
}
const view = ref('list');

onMounted(() => list.load());

const creating = ref(false);
const form = ref({ name: '', phone: '', birthday: '' });

const save = useApiForm(async () => {
    const cuerpo = { name: form.value.name };
    if (form.value.phone) cuerpo.phone = form.value.phone;
    if (form.value.birthday) cuerpo.birthday = form.value.birthday;

    const { data } = await api.post('/customers', cuerpo);

    creating.value = false;
    form.value = { name: '', phone: '', birthday: '' };

    // Recién creado: se va a su ficha para llenar el expediente si hace falta.
    router.visit(`/admin/clientes/${data.ulid}`);
});

function openCustomer(customer) {
    router.visit(`/admin/clientes/${customer.ulid}`);
}

/** Iniciales para el avatar de la tarjeta. */
function iniciales(nombre) {
    return (nombre ?? '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0] ?? '').join('').toUpperCase() || '?';
}

/** El saldo deudor, o `null` si no hay crédito o está en cero. */
function saldo(customer) {
    return customer.credit && Number(customer.credit.balance) > 0 ? `$${customer.credit.balance}` : null;
}

const columns = [
    { key: 'name', label: 'Cliente' },
    { key: 'phone', label: 'Teléfono', width: '11rem' },
    { key: 'balance', label: 'Saldo', width: '9rem' },
    { key: 'actions', label: '', width: '7rem' },
];
</script>

<template>
    <Head title="Clientes" />

    <ListHeader
        title="Clientes"
        subtitle="Alta express: sólo el nombre. El expediente —crédito, datos fiscales y direcciones— se completa en la ficha del cliente cuando hace falta."
        :count="list.meta.value?.total ?? null"
        v-model:search="list.filters.search"
        search-placeholder="Buscar por nombre o teléfono…"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="list.filters.status" class="input input--select">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="archived">Archivados</option>
            </select>

            <label class="con-deuda">
                <input
                    type="checkbox"
                    :checked="list.filters.with_debt === '1'"
                    @change="list.filters.with_debt = $event.target.checked ? '1' : ''"
                />
                Sólo con deuda
            </label>
        </template>

        <template #view>
            <ViewToggle v-model="view" persist-key="comandia:view:customers" class="toolbar__view" />
        </template>

        <template #action>
            <button v-can.write="'customers.customers.manage'" class="button" type="button" @click="creating = true">
                Nuevo cliente
            </button>
        </template>
    </ListHeader>

    <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

    <DataTable
        v-if="view === 'list'"
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay clientes que coincidan."
    >
        <template #cell:name="{ row }">
            <button class="row-link" type="button" @click="openCustomer(row)">{{ row.name }}</button>
        </template>

        <template #cell:phone="{ row }">{{ row.phone ?? '—' }}</template>

        <template #cell:balance="{ row }">
            <span v-if="saldo(row)" class="badge badge--warn money">{{ saldo(row) }}</span>
            <span v-else class="muted">—</span>
        </template>

        <template #cell:actions="{ row }">
            <button class="link-button" type="button" @click="openCustomer(row)">Abrir</button>
        </template>
    </DataTable>

    <ResourceGrid
        v-else
        :items="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay clientes que coincidan."
    >
        <template #card="{ item }">
            <button class="card card--link cliente-card" type="button" @click="openCustomer(item)">
                <span class="cliente-card__avatar" aria-hidden="true">{{ iniciales(item.name) }}</span>
                <span class="card__title">{{ item.name }}</span>
                <span class="card__meta">{{ item.phone ?? 'sin teléfono' }}</span>
                <span v-if="saldo(item)" class="badge badge--warn money">Debe {{ saldo(item) }}</span>
            </button>
        </template>
    </ResourceGrid>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="clientes" />

    <div v-if="creating" class="drawer-backdrop" @click.self="creating = false">
        <form class="drawer" @submit.prevent="save.submit()">
            <h2>Alta rápida de cliente</h2>

            <p class="field__hint">Sólo el nombre es obligatorio; el resto se completa en la ficha.</p>

            <p v-if="save.generalError.value" class="alert">{{ save.generalError.value }}</p>

            <label class="field">
                <span class="field__label">Nombre</span>
                <input v-model="form.name" class="input" required minlength="2" maxlength="120" />
                <span v-if="save.fieldErrors.value.name" class="field__error">{{ save.fieldErrors.value.name }}</span>
            </label>

            <label class="field">
                <span class="field__label">Teléfono</span>
                <input v-model="form.phone" class="input" maxlength="20" />
            </label>

            <label class="field">
                <span class="field__label">Cumpleaños</span>
                <input v-model="form.birthday" type="date" class="input" />
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="creating = false">Cancelar</button>
                <button type="submit" class="button" :disabled="save.processing.value">Guardar</button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.con-deuda { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; color: var(--color-suave); white-space: nowrap; }
.row-link {
    background: none; border: 0; padding: 0; font: inherit; font-weight: 500;
    color: var(--color-contenido); cursor: pointer;
    text-decoration: underline; text-decoration-color: var(--color-borde);
}
.muted { color: var(--color-suave); }
.money { font-variant-numeric: tabular-nums; }

.cliente-card { align-items: center; text-align: center; }
.cliente-card__avatar {
    display: grid; place-items: center; width: 3rem; height: 3rem; border-radius: 50%;
    background: color-mix(in srgb, var(--color-acento) 16%, var(--color-superficie));
    color: var(--color-acento); font-weight: 700; font-size: 1rem;
}
</style>
