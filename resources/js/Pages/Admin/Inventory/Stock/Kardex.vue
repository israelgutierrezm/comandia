<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '../../../../api/client';
import DataTable from '../../../../components/DataTable.vue';
import ListHeader from '../../../../components/ListHeader.vue';

/**
 * Kardex de un artículo (§6.2, §7).
 *
 * Se lee como un estado de cuenta: qué pasó, cuánto, y con qué saldo quedó. El `balance_after` viene
 * congelado del servidor y **no se acumula aquí** — acumular en el cliente es exactamente donde el
 * número deja de cuadrar, y por eso la columna existe en la tabla (P1).
 *
 * ## Paginación por CURSOR, no por página
 *
 * Es la tabla más grande del sistema y crece para siempre. Con `page=` habría que contar millones de
 * filas en cada petición, y el número de página cambiaría de significado en cuanto entrara un
 * movimiento nuevo. El cursor no tiene ninguno de los dos problemas — a cambio de no poder saltar a
 * la página 40, que nadie hace en un histórico.
 *
 * ## Los tipos vienen del servidor
 *
 * El selector no lleva las etiquetas escritas a mano: las pide a `/stock-movement-kinds`. Es la lección
 * de D139 — una lista duplicada en el cliente se desincroniza en la primera iteración que agregue un
 * tipo, y esta iteración agregó dos.
 */
const props = defineProps({
    articleUlid: { type: String, required: true },
});

const article = ref(null);
const stocks = ref([]);
const movements = ref([]);
const kinds = ref([]);
const cursors = ref([]);
const loading = ref(false);
const error = ref(null);
const filters = ref({ kind: '', warehouse: '' });
const nextCursor = ref(null);

onMounted(async () => {
    // Los tipos y la ficha del artículo, en paralelo: no dependen uno del otro.
    const [kindsResponse, articleResponse, stockResponse] = await Promise.all([
        api.get('/stock-movement-kinds'),
        api.get(`/articles/${props.articleUlid}`),
        api.get(`/articles/${props.articleUlid}/stock`),
    ]);

    kinds.value = kindsResponse.data;
    article.value = articleResponse.data;
    stocks.value = stockResponse.data;

    await loadPage();
});

/**
 * Carga una página del kardex.
 *
 * `cursor` nulo = desde el principio. Al filtrar se reinicia, porque un cursor pertenece a la consulta
 * que lo produjo: reusarlo con otro filtro daría una ventana de resultados que no corresponde a nada.
 */
async function loadPage(cursor = null) {
    loading.value = true;
    error.value = null;

    try {
        const response = await api.get(`/articles/${props.articleUlid}/kardex`, {
            kind: filters.value.kind,
            warehouse: filters.value.warehouse,
            cursor,
        });

        movements.value = response.data;
        nextCursor.value = response.meta?.next_cursor ?? null;

        if (cursor === null) {
            cursors.value = [];
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function applyFilters() {
    cursors.value = [];
    await loadPage(null);
}

const filtrosActivos = computed(() => (filters.value.kind !== '' ? 1 : 0));
function limpiarFiltros() {
    filters.value.kind = '';
    applyFilters();
}

async function goNext() {
    if (nextCursor.value === null) {
        return;
    }

    cursors.value.push(nextCursor.value);
    await loadPage(nextCursor.value);
}

async function goBack() {
    // Se descarta el cursor actual y se vuelve al anterior. Sin la pila no habría «atrás»: un cursor
    // sólo sabe avanzar.
    cursors.value.pop();
    await loadPage(cursors.value[cursors.value.length - 1] ?? null);
}

const columns = [
    { key: 'occurred_at', label: 'Cuándo', width: '11rem' },
    { key: 'kind', label: 'Movimiento', width: '13rem' },
    { key: 'warehouse', label: 'Almacén', width: '11rem' },
    { key: 'quantity', label: 'Cantidad', width: '9rem', align: 'right' },
    { key: 'balance_after', label: 'Saldo', width: '9rem', align: 'right' },
    { key: 'cost', label: 'Costo', width: '9rem', align: 'right' },
    { key: 'who', label: 'Quién / origen' },
];
</script>

<template>
    <Head :title="article ? `Kardex · ${article.name}` : 'Kardex'" />

    <p class="breadcrumb">
        <Link href="/admin/existencias" class="link-button">← Existencias</Link>
    </p>

    <ListHeader
        :title="article?.name ?? 'Kardex'"
        subtitle="El kardex es inmutable (§7): no se corrige, se le agrega. El saldo de cada renglón viene congelado del servidor — es el saldo que había justo después de ese movimiento."
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="filters.kind" class="input input--select" @change="applyFilters">
                <option value="">Todos los movimientos</option>
                <option v-for="kind in kinds" :key="kind.value" :value="kind.value">
                    {{ kind.label }}
                </option>
            </select>
        </template>
    </ListHeader>

    <section v-if="stocks.length" class="stock-summary">
        <div v-for="stock in stocks" :key="stock.warehouse?.ulid ?? 'sin-almacen'" class="stock-summary__item">
            <p class="stock-summary__label">{{ stock.warehouse?.name ?? '—' }}</p>
            <p class="stock-summary__value" :class="{ 'value--negative': stock.is_negative }">
                {{ stock.quantity }} {{ article?.base_unit?.code }}
            </p>
        </div>
    </section>


    <DataTable
        :columns="columns"
        :rows="movements"
        :loading="loading"
        :error="error"
        empty-message="Este artículo no tiene movimientos que coincidan."
    >
        <template #cell:occurred_at="{ row }">
            {{ row.occurred_at?.slice(0, 16).replace('T', ' ') }}
        </template>

        <template #cell:kind="{ row }">
            <span class="badge" :class="row.direction === 'in' ? 'badge--ok' : 'badge--warn'">
                {{ row.kind_label }}
            </span>
        </template>

        <template #cell:warehouse="{ row }">
            {{ row.warehouse?.name ?? '—' }}
        </template>

        <template #cell:quantity="{ row }">
            <!-- Con el signo ya aplicado por el servidor. La cantidad viaja siempre positiva y la
                 dirección aparte, para que ninguna suma pueda ignorar el signo por descuido. -->
            <span :class="row.direction === 'in' ? 'value--in' : 'value--out'">
                {{ row.signed_quantity }}
            </span>
        </template>

        <template #cell:balance_after="{ row }">
            {{ row.balance_after }}
        </template>

        <template #cell:cost="{ row }">
            <span v-if="row.total_cost !== null">{{ row.total_cost }}</span>
            <span v-else class="muted" title="El artículo no tenía costo capturado">sin costo</span>
        </template>

        <template #cell:who="{ row }">
            <div class="who">
                <!-- `null` = lo movió un job y no una persona. Se dice, en lugar de inventar un actor. -->
                <span v-if="row.actor">{{ row.actor.name }}</span>
                <span v-else class="muted">Sistema</span>

                <small v-if="row.source" class="muted">· {{ row.source.type }}</small>
                <small v-if="row.waste_reason" class="muted">· {{ row.waste_reason.name }}</small>
                <small v-if="row.lot" class="muted">· lote {{ row.lot.code }}</small>
                <small v-if="row.notes" class="muted">· {{ row.notes }}</small>
            </div>
        </template>
    </DataTable>

    <div class="pager">
        <button class="link-button" type="button" :disabled="cursors.length === 0" @click="goBack">
            ← Más reciente
        </button>
        <button class="link-button" type="button" :disabled="nextCursor === null" @click="goNext">
            Más antiguo →
        </button>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.breadcrumb {
    margin: 0 0 0.35rem;
    font-size: 0.85rem;
}

.stock-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.stock-summary__item {
    padding: 0.6rem 0.9rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    background: #fff;
}

.stock-summary__label {
    margin: 0;
    font-size: 0.78rem;
    color: #6b7280;
}

.stock-summary__value {
    margin: 0.15rem 0 0;
    font-weight: 600;
}

.value--negative {
    color: #b91c1c;
}

.value--in {
    color: #15803d;
}

.value--out {
    color: #b45309;
}

.muted {
    color: #6b7280;
}

.who {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    align-items: baseline;
}

.pager {
    display: flex;
    justify-content: space-between;
    margin-top: 0.75rem;
}
</style>
