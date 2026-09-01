<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import { useResourceList } from '../../../../stores/useResourceList';
import DataTable from '../../../../components/DataTable.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import FilterBar from '../../../../components/FilterBar.vue';

/**
 * Existencias (§6.2).
 *
 * La pantalla con la que se abre el día: «¿qué tengo y dónde?». Es de sólo lectura a propósito — la
 * existencia no se edita, se mueve, y cada forma de moverla tiene su documento.
 *
 * ## Los negativos no se esconden
 *
 * §6.2 los permite porque el POS nunca se bloquea, así que un saldo negativo no es un error a ocultar:
 * es la señal de que el conteo va atrasado. Se marcan en rojo y tienen filtro propio, porque son
 * exactamente la lista que el próximo conteo tiene que revisar.
 *
 * ## Sin buscador de texto
 *
 * El endpoint no declara columnas buscables y rechaza `?search=` con 422 (D182). Se filtra por artículo
 * y por almacén, que es como se pregunta de verdad: nadie busca existencias «por palabra».
 */
const list = useResourceList('/stocks', {
    initialFilters: { warehouse: '', article: '', only_negative: '', sort: 'quantity' },
});

// El orden por defecto («menor existencia primero») no cuenta como filtro; cambiarlo, sí.
const filtrosActivos = computed(
    () =>
        [
            list.filters.warehouse !== '',
            list.filters.only_negative === '1',
            list.filters.sort !== 'quantity',
        ].filter(Boolean).length,
);
function limpiarFiltros() {
    list.filters.warehouse = '';
    list.filters.only_negative = '';
    list.filters.sort = 'quantity';
}

const warehouses = ref([]);

onMounted(async () => {
    await list.load();

    // Sólo activos para el selector: filtrar por un almacén dado de baja daría una lista vacía sin
    // explicar por qué.
    warehouses.value = (await api.get('/warehouses', { status: 'active', per_page: 100 })).data;
});

/** Los almacenes que se pueden elegir. El de tránsito no: sólo lo escriben las transferencias (D190). */
const selectableWarehouses = computed(() => warehouses.value.filter((w) => w.kind !== 'transit'));

/** El de tránsito se ofrece aparte, y sólo para MIRAR: es la respuesta a «¿qué traigo en camiones?». */
const transitWarehouse = computed(() => warehouses.value.find((w) => w.kind === 'transit') ?? null);

const columns = [
    { key: 'article', label: 'Artículo' },
    { key: 'warehouse', label: 'Almacén', width: '12rem' },
    { key: 'lot', label: 'Lote', width: '10rem' },
    { key: 'quantity', label: 'Existencia', width: '10rem', align: 'right' },
    { key: 'value', label: 'Valor', width: '9rem', align: 'right' },
    { key: 'actions', label: '', width: '7rem' },
];
</script>

<template>
    <Head title="Existencias" />

    <header class="page-header">
        <div>
            <h1>Existencias</h1>
            <p class="page-header__hint">
                La existencia no se edita: se mueve, y cada movimiento tiene su documento. Un saldo
                <strong>negativo</strong> no es un error — significa que se vendió más de lo que el
                sistema creía tener, y es lo primero que el próximo conteo debe revisar.
            </p>
        </div>
    </header>

    <FilterBar :active-count="filtrosActivos" @clear="limpiarFiltros">
        <template #filters>
            <select v-model="list.filters.warehouse" class="input input--select">
                <option value="">Todos los almacenes</option>
                <option v-for="warehouse in selectableWarehouses" :key="warehouse.ulid" :value="warehouse.ulid">
                    {{ warehouse.name }}
                </option>
                <option v-if="transitWarehouse" :value="transitWarehouse.ulid">
                    — Mercancía en tránsito —
                </option>
            </select>

            <select v-model="list.filters.sort" class="input input--select">
                <option value="quantity">Menor existencia primero</option>
                <option value="-quantity">Mayor existencia primero</option>
                <option value="-updated_at">Movido más recientemente</option>
            </select>

            <label class="checkbox">
                <input v-model="list.filters.only_negative" type="checkbox" true-value="1" false-value="" />
                <span>Sólo negativos</span>
            </label>
        </template>
    </FilterBar>

    <DataTable
        :columns="columns"
        :rows="list.items.value"
        :loading="list.loading.value"
        :error="list.error.value"
        empty-message="No hay existencias registradas todavía. Aparecen al recibir una compra o registrar una entrada."
    >
        <template #cell:article="{ row }">
            {{ row.article?.name ?? '—' }}
        </template>

        <template #cell:warehouse="{ row }">
            {{ row.warehouse?.name ?? '—' }}
        </template>

        <template #cell:lot="{ row }">
            <span v-if="row.lot">
                {{ row.lot.code }}
                <small v-if="row.lot.expires_at" class="muted">· vence {{ row.lot.expires_at }}</small>
            </span>
            <span v-else class="muted">—</span>
        </template>

        <template #cell:quantity="{ row }">
            <span :class="{ 'value--negative': row.is_negative }">
                {{ row.quantity }} {{ row.article?.base_unit_code }}
            </span>
        </template>

        <template #cell:value="{ row }">
            <!-- `null` cuando el artículo no tiene costo capturado. Se dice, en lugar de pintar un cero
                 que afirmaría que la mercancía es gratis. -->
            <span v-if="row.total_value !== null">{{ row.total_value }}</span>
            <span v-else class="muted" title="El artículo no tiene costo capturado">sin costo</span>
        </template>

        <template #cell:actions="{ row }">
            <Link
                v-if="row.article"
                v-can="'inventory.kardex.view'"
                :href="`/admin/existencias/${row.article.ulid}/kardex`"
                class="link-button"
            >
                Kardex
            </Link>
        </template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="existencias" />
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.value--negative {
    color: #b91c1c;
    font-weight: 600;
}

.muted {
    color: #6b7280;
}

.checkbox {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
}
</style>
