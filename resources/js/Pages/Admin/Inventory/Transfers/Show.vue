<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useAuthorization } from '../../../../composables/useAuthorization';
import DataTable from '../../../../components/DataTable.vue';
import Icon from '../../../../components/Icon.vue';

/**
 * El documento de una transferencia (D25, D178–D190).
 *
 * ## La máquina de estados la decide el SERVIDOR
 *
 * `allowed_next` viene en el recurso, y esta pantalla no deduce nada: dibuja los botones que el servidor dice que se
 * pueden pulsar. Es la lección de D139 — si el cliente calcula las transiciones, acaba con su propia copia de la máquina
 * y se desincroniza en la primera iteración que añada un paso. Aquí importa más que en otros documentos, porque dos de
 * los cinco pasos son **omitibles por configuración** (D25): en un negocio pueden aparecer tres botones y en otro cinco,
 * sin que el código cambie.
 *
 * ## Enviar y recibir capturan cantidades; los otros pasos no
 *
 * Autorizar y preparar son firmas: no hay cifra que capturar. Enviar y recibir sí, y son distintas de lo pedido a
 * propósito: se pide 100, salen 90 y llegan 88. Cada cantidad se captura cuando ocurre su hecho físico, y de la
 * diferencia entre las dos sale lo que se perdió en el camino.
 *
 * ## Lo que no llegó no desaparece
 *
 * Al enviar, la mercancía sale del origen y entra al **almacén de tránsito** (D189): mientras viaja sigue existiendo y
 * se puede contar. Al recibir, sale del tránsito y entra al destino — y si llegó menos de lo que salió, la diferencia se
 * queda registrada como merma del tránsito con su motivo de sistema. Nada se evapora entre dos sucursales.
 */
const props = defineProps({
    transferUlid: { type: String, required: true },
});

const { canWrite } = useAuthorization();

const transfer = ref(null);
const loading = ref(true);
const error = ref(null);
const actionError = ref(null);
const working = ref(false);

/** El paso de captura abierto: `ship` o `receive`. `null` = no se está capturando nada. */
const capturing = ref(null);
const quantities = ref({});

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        transfer.value = (await api.get(`/transfers/${props.transferUlid}`)).data;
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar la transferencia.';
    } finally {
        loading.value = false;
    }
}

function lineKey(line) {
    return `${line.article.ulid}|${line.lot?.ulid ?? ''}`;
}

/** ¿El servidor permite este paso? No se deduce del estado: se lee de `allowed_next`. */
function permite(status) {
    return (transfer.value?.allowed_next ?? []).includes(status);
}

/**
 * Los pasos con su permiso, en orden.
 *
 * Cada paso tiene el suyo (D179) y no es burocracia: quien pide no debería poder autorizarse, y quien prepara no es
 * quien recibe. Separarlos es lo que hace que la transferencia sea evidencia de algo.
 */
const acciones = computed(() => [
    { status: 'authorized', label: 'Autorizar', permission: 'inventory.transfers.authorize', captura: false },
    { status: 'preparing', label: 'Preparar', permission: 'inventory.transfers.prepare', captura: false },
    { status: 'shipped', label: 'Enviar', permission: 'inventory.transfers.ship', captura: 'ship' },
    { status: 'received', label: 'Recibir', permission: 'inventory.transfers.receive', captura: 'receive' },
].filter((a) => permite(a.status) && canWrite(a.permission)));

/**
 * Abre la captura de cantidades con lo del paso anterior ya puesto.
 *
 * Prellenar con lo pedido (al enviar) o con lo enviado (al recibir) es lo correcto: el caso normal es que salga y llegue
 * todo, y obligar a reteclear diez renglones idénticos produce dedazos. Lo que NO se hace es prellenar y enviar sin que
 * nadie mire — la captura sigue siendo un paso explícito.
 */
function startCapture(paso) {
    quantities.value = Object.fromEntries(transfer.value.lines.map((l) => [
        lineKey(l),
        paso === 'ship' ? l.requested_quantity : l.shipped_quantity,
    ]));

    capturing.value = paso;
    actionError.value = null;
}

async function run(status, captura) {
    if (captura !== false) {
        startCapture(captura);

        return;
    }

    await post(status === 'authorized' ? 'authorize' : 'prepare');
}

async function post(accion, body = null) {
    working.value = true;
    actionError.value = null;

    try {
        transfer.value = (await api.post(`/transfers/${props.transferUlid}/${accion}`, body)).data;
        capturing.value = null;
    } catch (e) {
        actionError.value = e instanceof ApiError ? e.message : 'No se pudo completar el paso.';
    } finally {
        working.value = false;
    }
}

async function submitCapture() {
    await post(capturing.value, {
        lines: transfer.value.lines.map((l) => ({
            article_ulid: l.article.ulid,
            lot_ulid: l.lot?.ulid ?? null,
            quantity: quantities.value[lineKey(l)] === '' || quantities.value[lineKey(l)] === null
                ? '0'
                : quantities.value[lineKey(l)],
        })),
    });
}

async function cancel() {
    if (!window.confirm('¿Cancelar esta transferencia?')) {
        return;
    }

    await post('cancel');
}

const PASOS = [
    ['requested', 'Solicitada'],
    ['authorized', 'Autorizada'],
    ['prepared', 'Preparada'],
    ['shipped', 'Enviada'],
    ['received', 'Recibida'],
];

const columns = computed(() => [
    { key: 'article', label: 'Artículo' },
    { key: 'lot', label: 'Lote', width: '8rem' },
    { key: 'requested', label: 'Pedido', width: '8rem' },
    { key: 'shipped', label: 'Enviado', width: '8rem' },
    { key: 'received', label: 'Recibido', width: '8rem' },
    ...transfer.value?.has_shipped ? [{ key: 'difference', label: 'No llegó', width: '8rem' }] : [],
]);

function cantidad(valor) {
    return valor === null || valor === undefined
        ? '—'
        : Number(valor).toLocaleString('es-MX', { maximumFractionDigits: 4 });
}

function fecha(iso) {
    return iso === null || iso === undefined
        ? '—'
        : new Date(iso).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="transfer ? `Transferencia ${transfer.folio}` : 'Transferencia'" />

    <p v-if="loading" class="state">Cargando…</p>
    <p v-else-if="error" class="alert">{{ error }}</p>

    <template v-else>
        <header class="page-header">
            <div>
                <a href="/admin/transferencias" class="link">← Transferencias</a>
                <h1>Transferencia {{ transfer.folio }}</h1>
                <p class="page-header__hint">
                    {{ transfer.origin_warehouse?.name }} → {{ transfer.destination_warehouse?.name }}
                    · <span class="badge badge--warn">{{ transfer.status_label }}</span>
                </p>
            </div>

            <div class="page-header__actions">
                <button
                    v-if="transfer.is_open && canWrite('inventory.transfers.request')"
                    type="button"
                    class="link-button link-button--danger"
                    :disabled="working"
                    @click="cancel"
                ><Icon name="x" /> Cancelar</button>

                <!--
                    Los botones que el SERVIDOR permite, cruzados con lo que esta persona puede hacer. Dos filtros
                    distintos: el primero es el estado del documento, el segundo es quién eres.
                -->
                <button
                    v-for="accion in acciones"
                    :key="accion.status"
                    type="button"
                    class="button"
                    :disabled="working"
                    @click="run(accion.status, accion.captura)"
                >
                    {{ accion.label }}
                </button>
            </div>
        </header>

        <p v-if="actionError" class="alert">{{ actionError }}</p>

        <p v-if="transfer.is_open && acciones.length === 0" class="alert alert--notice">
            Esta transferencia espera un paso que <strong>no te corresponde dar</strong>. Cada paso tiene su permiso a
            propósito: quien pide no se autoriza a sí mismo, y quien prepara no es quien recibe.
        </p>

        <!-- La bitácora de los cinco pasos: quién y cuándo. Un paso sin fecha es un paso que no ha ocurrido. -->
        <ol class="steps">
            <li v-for="[clave, etiqueta] in PASOS" :key="clave" :class="{ 'steps__item--done': transfer.steps[clave] }">
                <span class="steps__label">{{ etiqueta }}</span>
                <span v-if="transfer.steps[clave]" class="steps__value">
                    {{ transfer.steps[clave].by?.name ?? '—' }}
                    <span class="muted">{{ fecha(transfer.steps[clave].at) }}</span>
                </span>
                <span v-else class="muted">pendiente</span>
            </li>
        </ol>

        <DataTable
            :columns="columns"
            :rows="transfer.lines"
            :loading="false"
            :error="null"
            empty-message="Esta transferencia no tiene renglones."
        >
            <template #cell:article="{ row }">
                {{ row.article.name }}
                <span class="muted">{{ row.article.base_unit_code }}</span>
            </template>

            <template #cell:lot="{ row }">
                <span v-if="row.lot">{{ row.lot.code }}</span>
                <span v-else class="muted">—</span>
            </template>

            <template #cell:requested="{ row }">{{ cantidad(row.requested_quantity) }}</template>
            <template #cell:shipped="{ row }">{{ cantidad(row.shipped_quantity) }}</template>
            <template #cell:received="{ row }">{{ cantidad(row.received_quantity) }}</template>

            <template #cell:difference="{ row }">
                <!--
                    Lo que salió y no llegó, calculado por la base. Cero es la respuesta buena; un número distinto de
                    cero ya está registrado como merma del tránsito, con su motivo.
                -->
                <span :class="{ 'is-negative': Number(row.transit_difference) > 0 }">
                    {{ cantidad(row.transit_difference) }}
                </span>
            </template>
        </DataTable>

        <div v-if="capturing" class="drawer-backdrop" @click.self="capturing = null">
            <form class="drawer" @submit.prevent="submitCapture">
                <h2>{{ capturing === 'ship' ? 'Enviar' : 'Recibir' }}</h2>

                <p class="drawer__hint">
                    <template v-if="capturing === 'ship'">
                        Captura lo que <strong>de verdad sale</strong>, que puede ser menos de lo pedido. Al enviar, la
                        mercancía deja el almacén de origen y entra al almacén de tránsito: mientras viaja sigue siendo
                        tuya y se puede contar.
                    </template>
                    <template v-else>
                        Captura lo que <strong>de verdad llega</strong>. Si llega menos de lo que salió, la diferencia se
                        registra como merma del tránsito con su motivo — no se evapora ni se le carga al almacén que
                        recibe.
                    </template>
                </p>

                <p v-if="actionError" class="alert">{{ actionError }}</p>

                <table class="lines">
                    <thead>
                        <tr>
                            <th>Artículo</th>
                            <th style="width: 7rem">{{ capturing === 'ship' ? 'Pedido' : 'Enviado' }}</th>
                            <th style="width: 9rem">{{ capturing === 'ship' ? 'Sale' : 'Llega' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in transfer.lines" :key="lineKey(line)">
                            <td>
                                {{ line.article.name }}
                                <span class="muted">{{ line.article.base_unit_code }}</span>
                            </td>
                            <td>
                                {{ cantidad(capturing === 'ship' ? line.requested_quantity : line.shipped_quantity) }}
                            </td>
                            <td>
                                <input
                                    v-model="quantities[lineKey(line)]"
                                    class="input"
                                    inputmode="decimal"
                                    required
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="capturing = null"><Icon name="x" /> Cancelar</button>
                    <button type="submit" class="button" :disabled="working">
                        {{ capturing === 'ship' ? 'Confirmar envío' : 'Confirmar recepción' }}
                    </button>
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

.page-header__actions {
    display: flex;
    gap: 0.6rem;
    align-items: center;
}

.is-negative {
    color: #b91c1c;
}

.steps {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    list-style: none;
    margin: 0 0 1rem;
    padding: 0;
}

.steps li {
    flex: 1 1 8rem;
    padding: 0.5rem 0.65rem;
    border: 1px solid #e7e5e4;
    border-radius: 0.5rem;
    font-size: 0.85rem;
}

.steps__item--done {
    border-color: #86efac;
    background: #f0fdf4;
}

.steps__label {
    display: block;
    font-weight: 600;
}

.steps__value {
    display: block;
}

.lines {
    width: 100%;
    border-collapse: collapse;
    margin: 0.5rem 0 0.9rem;
    font-size: 0.9rem;
}

.lines th,
.lines td {
    padding: 0.35rem 0.4rem;
    text-align: left;
    border-bottom: 1px solid #e7e5e4;
}
</style>
