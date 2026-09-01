<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useAuthorization } from '../../../../composables/useAuthorization';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * Una orden de producción (§6.2, D192–D200).
 *
 * ## Dos tablas que nunca coexisten
 *
 * Un borrador trae `preview`: lo que consumiría con la receta de **hoy**. Una orden completada trae `lines`: lo que
 * consumió de verdad, con la receta congelada en el instante de producir (D196). El recurso manda uno u otro, así que
 * esta pantalla no deduce cuál mostrar según el estado — muestra el que venga con contenido.
 *
 * Y la distinción no es cosmética: si la receta cambia mañana, la previsualización de un borrador cambia con ella y los
 * renglones de una orden completada **no**. Por eso una explica un plan y la otra explica un hecho.
 *
 * ## Se puede producir menos de lo planeado
 *
 * Completar acepta una cantidad distinta a la planeada, y el consumo se recalcula proporcionalmente: si se planearon 10
 * litros y salieron 8, se consumen los insumos de 8. Lo que no se puede es producir de más sin decirlo — la cantidad
 * producida es un dato que alguien captura, no el plan dado por bueno.
 *
 * ## El POS no se bloquea, y aquí tampoco
 *
 * Producir con insumos insuficientes está permitido: la salsa ya se hizo, y negarlo en el sistema sólo conseguiría que
 * nadie la registrara (§6.2). Las existencias quedan negativas y eso es información, no un error.
 */
const props = defineProps({
    orderUlid: { type: String, required: true },
});

const { canWrite } = useAuthorization();

const order = ref(null);
const loading = ref(true);
const error = ref(null);
const actionError = ref(null);
const working = ref(false);

const completing = ref(false);
const producedQuantity = ref('');

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        order.value = (await api.get(`/production-orders/${props.orderUlid}`)).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la orden.';
    } finally {
        loading.value = false;
    }
}

function startComplete() {
    // Se prellena con lo planeado porque es el caso normal, y se deja editable porque el caso que importa es el otro.
    producedQuantity.value = order.value.planned_quantity;
    actionError.value = null;
    completing.value = true;
}

async function complete() {
    working.value = true;
    actionError.value = null;

    try {
        order.value = (await api.post(`/production-orders/${props.orderUlid}/complete`, {
            produced_quantity: producedQuantity.value,
        })).data;

        completing.value = false;
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo completar la orden.';
    } finally {
        working.value = false;
    }
}

async function cancel() {
    if (!window.confirm('¿Cancelar esta orden? No se consumió nada, así que no hay nada que revertir.')) {
        return;
    }

    working.value = true;

    try {
        order.value = (await api.post(`/production-orders/${props.orderUlid}/cancel`)).data;
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo cancelar la orden.';
    } finally {
        working.value = false;
    }
}

const esBorrador = computed(() => order.value?.status === 'draft');

/**
 * Lo que costó el consumo, sumando los renglones.
 *
 * ## Y por qué puede NO coincidir con el valor de lo producido
 *
 * Son dos cifras legítimas y distintas. «Valor de lo producido» usa el costo vigente del producible en el instante de
 * producir, congelado en el documento; el consumo usa el costo vigente de cada insumo, también congelado. Y el costo de
 * un producible se **deriva** de su receta (D16) mediante un recosteo asíncrono: si un insumo subió y la cascada
 * todavía no corrió, el costo del producible va por detrás del de sus componentes.
 *
 * Lo vi con esta orden: los renglones sumaban 54.68 y el total decía 46.20, porque el jitomate había subido de 0.0320 a
 * 0.0400 y la salsa seguía costeada con el precio viejo. Ninguna de las dos cifras estaba mal — lo que estaba mal era no
 * decir que existían las dos. Una contradicción muda en una pantalla de costos hace que se deje de creer en toda ella,
 * así que la diferencia se muestra y se explica: es la señal de que falta recostear.
 */
const costoDelConsumo = computed(() => {
    if (esBorrador.value || (order.value?.lines ?? []).length === 0) {
        return null;
    }

    // Se suman los renglones tal como los mandó el servidor: cada `line_cost` ya viene redondeado allá, y volver a
    // multiplicar aquí sería la segunda copia de una multiplicación de dinero (D134).
    return order.value.lines.reduce((total, l) => total + Number(l.line_cost ?? 0), 0);
});

/** ¿Las dos cifras difieren de forma visible? Un centavo de redondeo no merece una explicación. */
const difiereDelConsumo = computed(() => costoDelConsumo.value !== null
    && order.value?.total_cost !== null
    && Math.abs(costoDelConsumo.value - Number(order.value.total_cost)) >= 0.02);

const filas = computed(() => esBorrador.value ? (order.value?.preview ?? []) : (order.value?.lines ?? []));

const columns = computed(() => [
    { key: 'component', label: 'Insumo' },
    { key: 'recipe', label: 'Receta', width: '11rem' },
    { key: 'quantity', label: esBorrador.value ? 'Consumiría' : 'Consumió', width: '9rem' },
    ...esBorrador.value ? [] : [
        { key: 'unit_cost', label: 'Costo unitario', width: '9rem' },
        { key: 'line_cost', label: 'Costo', width: '9rem' },
    ],
]);

function cantidad(valor) {
    return valor === null || valor === undefined
        ? '—'
        : Number(valor).toLocaleString('es-MX', { maximumFractionDigits: 4 });
}

function dinero(valor) {
    return valor === null || valor === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(valor));
}

function fecha(iso) {
    return iso === null || iso === undefined
        ? '—'
        : new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="order ? `Producción · ${order.article?.name}` : 'Producción'" />

    <template v-if="loading"></template>
    <p v-else-if="error" class="alert">{{ error }}</p>

    <template v-else>
        <header class="page-header">
            <div>
                <a href="/admin/produccion" class="link">← Producción</a>
                <h1>{{ order.article?.name }}</h1>
                <p class="page-header__hint">
                    <span class="badge" :class="order.status === 'completed' ? 'badge--ok' : 'badge--warn'">
                        {{ order.status_label }}
                    </span>
                    · {{ order.warehouse?.name }}
                    · planeó {{ order.created_by?.name ?? '—' }}
                    <template v-if="order.produced_by"> · produjo {{ order.produced_by.name }}</template>
                </p>
            </div>

            <div v-if="order.is_open && canWrite('inventory.production.create')" class="page-header__actions">
                <button type="button" class="link-button link-button--danger" :disabled="working" @click="cancel"><Icon name="x" /> Cancelar orden</button>
                <button type="button" class="button" :disabled="working" @click="startComplete"><Icon name="check" /> Completar producción</button>
            </div>
        </header>

        <p v-if="actionError" class="alert">{{ actionError }}</p>

        <p v-if="esBorrador" class="alert alert--notice">
            Esto es un <strong>borrador</strong>: nada se ha consumido y nada se ha dado de alta. Lo de abajo es lo que
            consumiría con la receta de hoy — si la receta cambia antes de completar, cambia con ella. Al completar se
            congela la que esté vigente en ese momento.
        </p>

        <dl class="facts">
            <div class="fact">
                <dt class="fact__label">Planeado</dt>
                <dd class="fact__value">
                    {{ cantidad(order.planned_quantity) }} <span class="muted">{{ order.article?.base_unit_code }}</span>
                </dd>
            </div>

            <div class="fact">
                <dt class="fact__label">Producido</dt>
                <dd class="fact__value fact__value--strong">
                    {{ cantidad(order.produced_quantity) }}
                    <span class="muted">{{ order.article?.base_unit_code }}</span>
                </dd>
            </div>

            <div class="fact">
                <dt class="fact__label">Costo unitario</dt>
                <dd class="fact__value">{{ dinero(order.unit_cost_at_production) }}</dd>
            </div>

            <div class="fact">
                <dt class="fact__label">Valor de lo producido</dt>
                <dd class="fact__value fact__value--strong">{{ dinero(order.total_cost) }}</dd>
            </div>

            <div v-if="costoDelConsumo !== null" class="fact">
                <dt class="fact__label">Costo del consumo</dt>
                <dd class="fact__value fact__value--strong">{{ dinero(costoDelConsumo) }}</dd>
            </div>

            <div class="fact">
                <dt class="fact__label">Producido el</dt>
                <dd class="fact__value">{{ fecha(order.produced_at) }}</dd>
            </div>
        </dl>

        <p v-if="order.notes" class="notes">{{ order.notes }}</p>

        <p v-if="difiereDelConsumo" class="alert alert--notice">
            El <strong>costo del consumo</strong> ({{ dinero(costoDelConsumo) }}) y el
            <strong>valor de lo producido</strong> ({{ dinero(order.total_cost) }}) no coinciden, y las dos cifras son
            correctas: la segunda usa el costo vigente del producible, que se deriva de su receta y se recalcula en
            segundo plano. La diferencia significa que algún insumo cambió de precio y la salsa —o lo que sea— todavía
            está costeada con el anterior. Es la señal de que falta recostear, no un error de captura.
        </p>

        <DataTable
            :columns="columns"
            :rows="filas"
            :loading="false"
            :error="null"
            :empty-message="esBorrador
                ? 'La receta de este artículo no tiene componentes, así que no hay nada que consumir.'
                : 'Esta orden no consumió nada.'"
        >
            <template #cell:component="{ row }">
                {{ row.component.name }}
                <span class="muted">{{ row.component.base_unit_code }}</span>
            </template>

            <template #cell:recipe="{ row }">
                {{ cantidad(row.recipe?.quantity) }}
                <span class="muted">{{ row.recipe?.unit_code ?? '' }}</span>
                <!--
                    El rendimiento explica por qué se consume más de lo que dice la receta: una cebolla al 80% obliga a
                    tomar 125 g para tener 100 g útiles. Sin verlo, la cantidad consumida parece un error de captura.
                -->
                <span v-if="row.recipe?.yield_percent" class="muted">· rend. {{ row.recipe.yield_percent }}%</span>
            </template>

            <template #cell:quantity="{ row }">{{ cantidad(row.quantity ?? row.consumed_quantity) }}</template>

            <template #cell:unit_cost="{ row }">{{ dinero(row.unit_cost_at_production) }}</template>

            <template #cell:line_cost="{ row }">{{ dinero(row.line_cost) }}</template>
        </DataTable>

        <div v-if="completing" class="drawer-backdrop" @click.self="completing = false">
            <form class="drawer drawer--narrow" @submit.prevent="complete">
                <FormHeader title="Completar producción" />

                <p class="drawer__hint">
                    Captura lo que <strong>de verdad salió</strong>. El consumo se recalcula con esa cantidad: si se
                    planearon 10 y salieron 8, se consumen los insumos de 8. Al confirmar, el inventario se mueve y la
                    receta usada queda congelada en el documento.
                </p>

                <p v-if="actionError" class="alert">{{ actionError }}</p>

                <label class="field">
                    <span class="field__label">
                        Cantidad producida
                        <span class="muted">en {{ order.article?.base_unit_code }}</span>
                    </span>
                    <input v-model="producedQuantity" class="input" inputmode="decimal" required />
                    <span class="field__hint">
                        Se puede producir aunque falten insumos: la mercancía ya se hizo, y negarlo sólo conseguiría que
                        nadie lo registrara. Las existencias quedarían en negativo, que es información y no un error.
                    </span>
                </label>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="completing = false"><Icon name="x" /> Cancelar</button>
                    <button type="submit" class="button" :disabled="working"><Icon name="check" /> Confirmar producción</button>
                </div>
            </form>
        </div>
    </template>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.state {
    padding: 1.5rem 0;
    color: #6b7280;
}

.muted {
    color: #6b7280;
    font-size: 0.85rem;
}

.link {
    color: #1d4ed8;
    text-decoration: none;
    font-size: 0.85rem;
}

.link:hover {
    text-decoration: underline;
}

.drawer__hint {
    margin: 0 0 0.9rem;
    color: #6b7280;
    font-size: 0.85rem;
}

.drawer--narrow {
    max-width: 26rem;
}

.page-header__actions {
    display: flex;
    gap: 0.6rem;
    align-items: center;
}

.facts {
    display: flex;
    flex-wrap: wrap;
    gap: 1.25rem;
    margin: 0 0 1rem;
}

.fact__label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6b7280;
}

.fact__value {
    margin: 0.15rem 0 0;
    font-size: 0.95rem;
}

.fact__value--strong {
    font-weight: 600;
}

.notes {
    margin: 0 0 1rem;
    font-size: 0.9rem;
    color: #57534e;
}
</style>
