<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api, ApiError, orEmptyWhenForbidden } from '../../../../api/client';
import { useResourceList } from '../../../../stores/useResourceList';
import { useAuthorization } from '../../../../composables/useAuthorization';
import { formatMoney } from '../../../../support/money';
import DataTable from '../../../../components/DataTable.vue';
import Paginacion from '../../../../components/Paginacion.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Icon from '../../../../components/Icon.vue';
import StockMovementDrawer from '../../../../components/inventory/StockMovementDrawer.vue';
import { formatCalendarDate } from '../../../../components/inventory/inventoryFormat';

/**
 * Existencias (§6.2).
 *
 * La pantalla con la que se abre el día: «¿qué tengo y dónde?». La tabla es de sólo lectura a propósito — la
 * existencia no se edita, se mueve, y cada forma de moverla tiene su documento. Lo que sí se puede desde aquí es
 * REGISTRAR un movimiento manual (entrada, salida o ajuste), que es un renglón más del kardex y no una edición del
 * saldo: el panel lo dice y lleva al kardex del artículo para verlo.
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
const warehousesError = ref(null);

onMounted(async () => {
    // La lista no depende del catálogo de almacenes: van en paralelo, y si el catálogo falla la lista se ve igual.
    await Promise.all([list.load(), loadWarehouses()]);
});

/**
 * El catálogo del filtro. Sólo activos: filtrar por un almacén dado de baja daría una lista vacía sin explicar por qué.
 *
 * Un 403 no es un error aquí: el rol puede ver existencias sin «Ver almacenes» (el Almacenista de la plantilla), y
 * entonces sólo se queda sin filtro. Cualquier otro fallo se dice: antes se perdía en la consola y el filtro aparecía
 * vacío sin explicación.
 */
async function loadWarehouses() {
    try {
        warehouses.value = (await orEmptyWhenForbidden(api.get('/warehouses', { status: 'active', per_page: 100 }))).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        warehousesError.value = e.message;
    }
}

/** Los almacenes que se pueden elegir. El de tránsito no: sólo lo escriben las transferencias (D190). */
const selectableWarehouses = computed(() => warehouses.value.filter((w) => w.kind !== 'transit'));

/** El de tránsito se ofrece aparte, y sólo para MIRAR: es la respuesta a «¿qué traigo en camiones?». */
const transitWarehouse = computed(() => warehouses.value.find((w) => w.kind === 'transit') ?? null);

const { canWrite } = useAuthorization();

/** ¿Puede registrar al menos uno de los tres movimientos manuales? Cada uno tiene su permiso (D158). */
const canMoveStock = computed(() => [
    'inventory.entries.create',
    'inventory.exits.create',
    'inventory.adjustments.create',
].some((permission) => canWrite(permission)));

/**
 * El panel de movimiento abierto: `{ article, warehouse }` para abrirlo desde un renglón con los dos ya elegidos, o con
 * los dos en `null` desde el botón general. `null` = cerrado.
 */
const moving = ref(null);

function openMovement(row = null) {
    moving.value = { article: row?.article ?? null, warehouse: row?.warehouse ?? null };
}

/** Lo que mueve el tránsito son las transferencias: su renglón no ofrece movimiento manual (daría 422). */
function isTransit(row) {
    return transitWarehouse.value !== null && row.warehouse?.ulid === transitWarehouse.value.ulid;
}

const columns = [
    { key: 'article', label: 'Artículo' },
    { key: 'warehouse', label: 'Almacén', width: '12rem' },
    { key: 'lot', label: 'Lote', width: '11rem' },
    { key: 'quantity', label: 'Existencia', width: '10rem', align: 'right' },
    { key: 'value', label: 'Valor', width: '9rem', align: 'right' },
    { key: 'actions', label: '', width: '17rem' },
];
</script>

<template>
    <Head title="Existencias" />

    <ListHeader
        title="Existencias"
        subtitle="La existencia no se edita: se mueve, y cada movimiento tiene su documento. Un saldo negativo no es un error — significa que se vendió más de lo que el sistema creía tener, y es lo primero que el próximo conteo debe revisar."
        :count="list.meta.value?.total ?? null"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select
                v-if="warehouses.length > 0"
                v-model="list.filters.warehouse"
                class="input input--select"
                aria-label="Almacén"
            >
                <option value="">Todos los almacenes</option>
                <option v-for="warehouse in selectableWarehouses" :key="warehouse.ulid" :value="warehouse.ulid">
                    {{ warehouse.name }}
                </option>
                <option v-if="transitWarehouse" :value="transitWarehouse.ulid">
                    — Mercancía en tránsito —
                </option>
            </select>

            <select v-model="list.filters.sort" class="input input--select" aria-label="Orden">
                <option value="quantity">Menor existencia primero</option>
                <option value="-quantity">Mayor existencia primero</option>
                <option value="-updated_at">Movido más recientemente</option>
            </select>

            <label class="checkbox">
                <input v-model="list.filters.only_negative" type="checkbox" true-value="1" false-value="" />
                <span>Sólo negativos</span>
            </label>
        </template>

        <template #action>
            <button v-if="canMoveStock" class="button" type="button" @click="openMovement()">
                <Icon name="plus" /> Registrar movimiento
            </button>
        </template>
    </ListHeader>

    <p v-if="warehousesError" class="alert" role="alert">
        No se pudo cargar la lista de almacenes, así que por ahora no se puede filtrar por almacén: {{ warehousesError }}
    </p>

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
                <!-- Fecha de calendario: se pinta tal cual es, sin pasarla por la zona (la correría un día). -->
                <small v-if="row.lot.expires_at" class="muted">· vence {{ formatCalendarDate(row.lot.expires_at) }}</small>
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
            <span v-if="row.total_value !== null">{{ formatMoney(row.total_value) }}</span>
            <span v-else class="muted" title="El artículo no tiene costo capturado">sin costo</span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <Link
                    v-if="row.article"
                    v-can="'inventory.kardex.view'"
                    :href="`/admin/existencias/${row.article.ulid}/kardex`"
                    class="link-button"
                >
                    Kardex
                </Link>

                <!-- Sólo en los renglones con lote: es la única pista de que el artículo los lleva (el saldo no la trae). -->
                <Link v-if="row.article && row.lot" :href="`/admin/existencias/${row.article.ulid}/lotes`" class="link-button">
                    Lotes
                </Link>

                <button
                    v-if="canMoveStock && row.article && !isTransit(row)"
                    type="button"
                    class="link-button"
                    @click="openMovement(row)"
                >
                    <Icon name="plus" /> Movimiento
                </button>
            </div>
        </template>
    </DataTable>

    <Paginacion :meta="list.meta.value" v-model:page="list.filters.page" item-label="existencias" />

    <!-- Al registrar, la lista se recarga: el saldo nuevo se ve aquí, y el panel lleva al kardex del artículo. -->
    <StockMovementDrawer
        v-if="moving"
        :article="moving.article"
        :warehouse="moving.warehouse"
        @close="moving = null"
        @recorded="list.load()"
    />
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.value--negative {
    color: var(--color-peligro);
    font-weight: 600;
}

.muted {
    color: var(--color-suave);
}

.checkbox {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.9rem;
}
</style>
