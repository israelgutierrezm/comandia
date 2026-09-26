<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useAuthorization } from '../../../../composables/useAuthorization';
import { formatInBranchTime } from '../../../../support/datetime';
import { formatMoney } from '../../../../support/money';
import DataTable from '../../../../components/DataTable.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Icon from '../../../../components/Icon.vue';
import StockMovementDrawer from '../../../../components/inventory/StockMovementDrawer.vue';

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
 *
 * ## Cada lectura por su lado
 *
 * Los tipos, la ficha del artículo y sus saldos son de apoyo; el kardex es la pantalla. Antes se pedían los
 * tres juntos y el kardex esperaba a que llegaran: si uno fallaba —la ficha pide «Ver artículos», los saldos
 * «Ver existencias», y un rol puede tener el kardex sin ellos—, el kardex no se pedía y la tabla decía «no
 * tiene movimientos», que es falso. Ahora el kardex se carga siempre, un 403 de apoyo sólo esconde lo suyo, y
 * cualquier otro fallo se dice.
 */
const props = defineProps({
    articleUlid: { type: String, required: true },
});

const page = usePage();
const { canWrite } = useAuthorization();

const article = ref(null);
const stocks = ref([]);
const movements = ref([]);
const kinds = ref([]);
const cursors = ref([]);
const loading = ref(false);

/** El error del kardex, como `ApiError`: la tabla lo necesita así para distinguir el 403 del resto. */
const error = ref(null);
const filters = ref({ kind: '', warehouse: '' });
const nextCursor = ref(null);

/** Los fallos de las lecturas de apoyo, por lectura: `{ kinds, article, stocks }` → mensaje. */
const supportErrors = ref({});

onMounted(async () => {
    await Promise.all([loadKinds(), loadArticle(), loadStocks(), loadPage()]);
});

/** Lee una lectura de apoyo. Un 403 sólo esconde lo suyo; cualquier otro fallo se anota para decirlo. */
async function loadSupport(key, request, apply) {
    try {
        apply((await request).data ?? null);
        delete supportErrors.value[key];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (e.status !== 403) {
            supportErrors.value[key] = e.message;
        }
    }
}

const loadKinds = () => loadSupport('kinds', api.get('/stock-movement-kinds'), (data) => {
    kinds.value = data ?? [];
});

const loadArticle = () => loadSupport('article', api.get(`/articles/${props.articleUlid}`), (data) => {
    article.value = data;
});

const loadStocks = () => loadSupport('stocks', api.get(`/articles/${props.articleUlid}/stock`), (data) => {
    stocks.value = data ?? [];
});

const SUPPORT_LABELS = {
    kinds: 'los tipos de movimiento (para filtrar)',
    article: 'la ficha del artículo',
    stocks: 'la existencia por almacén',
};

const supportErrorList = computed(() => Object.entries(supportErrors.value)
    .map(([key, message]) => `${SUPPORT_LABELS[key]}: ${message}`));

/** El nombre y la unidad: de la ficha, o —sin permiso de catálogo— de los saldos, que también los traen. */
const articleName = computed(() => article.value?.name ?? stocks.value[0]?.article?.name ?? null);
const unitCode = computed(() => article.value?.base_unit?.code ?? stocks.value[0]?.article?.base_unit_code ?? '');

/** ¿Lleva lotes? Lo dice la ficha; sin ella, que algún saldo tenga lote. */
const tracksLots = computed(() => (typeof article.value?.tracks_lots === 'boolean'
    ? article.value.tracks_lots
    : stocks.value.some((stock) => stock.lot)));

/** La hora de cada movimiento en la de la sucursal activa, no en UTC crudo ni en la del navegador (§7). */
function occurredAt(iso) {
    return formatInBranchTime(iso, page.props.context?.branch_timezone) || '—';
}

const canMoveStock = computed(() => [
    'inventory.entries.create',
    'inventory.exits.create',
    'inventory.adjustments.create',
].some((permission) => canWrite(permission)));

/**
 * El artículo para el panel de movimiento. Sin ficha (el rol no ve el catálogo) se arma con lo que dicen los saldos, y
 * el panel completa el resto por su cuenta.
 */
const movementArticle = computed(() => article.value ?? {
    ulid: props.articleUlid,
    name: articleName.value ?? 'este artículo',
    base_unit_code: unitCode.value,
});

const moving = ref(false);

/** Tras registrar, el kardex vuelve al principio —el movimiento nuevo es el primero— y los saldos se releen. */
async function onRecorded() {
    cursors.value = [];
    await Promise.all([loadStocks(), loadPage(null)]);
}

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
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // El `ApiError` completo y no su texto: la tabla lee `isForbidden` y `message`, y con una cadena pintaba la caja
        // de error vacía.
        error.value = e;
        movements.value = [];
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
    <Head :title="articleName ? `Kardex · ${articleName}` : 'Kardex'" />

    <p class="breadcrumb">
        <Link href="/admin/existencias" class="link-button">← Existencias</Link>
    </p>

    <ListHeader
        :title="articleName ?? 'Kardex'"
        subtitle="El kardex es inmutable (§7): no se corrige, se le agrega. El saldo de cada renglón viene congelado del servidor — es el saldo que había justo después de ese movimiento."
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-model="filters.kind" class="input input--select" aria-label="Tipo de movimiento" @change="applyFilters">
                <option value="">Todos los movimientos</option>
                <option v-for="kind in kinds" :key="kind.value" :value="kind.value">
                    {{ kind.label }}
                </option>
            </select>
        </template>

        <template #action>
            <Link
                v-if="tracksLots"
                :href="`/admin/existencias/${props.articleUlid}/lotes`"
                class="button button--neutral"
            >Lotes</Link>
            <button v-if="canMoveStock" type="button" class="button" @click="moving = true">
                <Icon name="plus" /> Registrar movimiento
            </button>
        </template>
    </ListHeader>

    <div v-if="supportErrorList.length > 0" class="alert" role="alert">
        <p class="alert__title">No se pudo cargar parte de la pantalla; el kardex de abajo no depende de esto.</p>
        <ul class="alert__list">
            <li v-for="message in supportErrorList" :key="message">{{ message }}</li>
        </ul>
    </div>

    <!-- Un saldo por almacén y por lote, tal como los manda el servidor: aquí no se suma nada. -->
    <section v-if="stocks.length" class="stock-summary">
        <div
            v-for="stock in stocks"
            :key="`${stock.warehouse?.ulid ?? 'sin-almacen'}|${stock.lot?.ulid ?? 'sin-lote'}`"
            class="stock-summary__item"
        >
            <p class="stock-summary__label">
                {{ stock.warehouse?.name ?? '—' }}
                <template v-if="stock.lot"> · lote {{ stock.lot.code }}</template>
                <template v-else-if="tracksLots"> · sin lote</template>
            </p>
            <p class="stock-summary__value" :class="{ 'value--negative': stock.is_negative }">
                {{ stock.quantity }} {{ unitCode }}
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
            {{ occurredAt(row.occurred_at) }}
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
            <span v-if="row.total_cost !== null">{{ formatMoney(row.total_cost) }}</span>
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

    <!-- El artículo va fijo: el kardex es de él, y un movimiento de otro no se vería reflejado aquí. -->
    <StockMovementDrawer
        v-if="moving"
        :article="movementArticle"
        lock-article
        :show-kardex-link="false"
        @close="moving = false"
        @recorded="onRecorded"
    />
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.breadcrumb {
    margin: 0 0 0.35rem;
    font-size: 0.85rem;
}

.alert__title {
    margin: 0;
}

.alert__list {
    margin: 0.35rem 0 0;
    padding-left: 1.1rem;
}

.stock-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.stock-summary__item {
    padding: 0.6rem 0.9rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
}

.stock-summary__label {
    margin: 0;
    font-size: 0.78rem;
    color: var(--color-suave);
}

.stock-summary__value {
    margin: 0.15rem 0 0;
    font-weight: 600;
}

.value--negative {
    color: var(--color-peligro);
}

.value--in {
    color: var(--color-exito);
}

.value--out {
    color: var(--color-aviso);
}

.muted {
    color: var(--color-suave);
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
