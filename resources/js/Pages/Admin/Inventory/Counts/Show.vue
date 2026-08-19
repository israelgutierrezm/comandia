<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useAuthorization } from '../../../../composables/useAuthorization';
import DataTable from '../../../../components/DataTable.vue';
import PinAuthorizationDialog from '../../../../components/inventory/PinAuthorizationDialog.vue';

/**
 * La hoja de conteo (§6.2, D172–D177).
 *
 * ## El conteo ciego, y por qué esta pantalla se ve distinta según quién la abra
 *
 * Quien tiene sólo `inventory.counts.create` —el almacenista— **no ve** la cantidad esperada, ni la diferencia, ni la
 * valuación: el servidor no las manda (D174). No es una pantalla incompleta, es el control: si el renglón dijera
 * «esperado 40», quien cuenta escribe 40 y no cuenta. El conteo dejaría de ser evidencia y sería una confirmación de lo
 * que el sistema ya creía.
 *
 * Quien tiene `inventory.counts.close` sí las ve, porque es quien revisa antes de cerrar — y es ahí donde se detecta un
 * dedazo, no en la captura.
 *
 * ## Vacío no es cero
 *
 * `null` en la cantidad contada significa **no contado**; `0` significa «fui, miré y no había nada». Son ajustes
 * distintos al cerrar: el primero deja el saldo como estaba, el segundo lo baja a cero. El campo los distingue a
 * propósito y por eso no se rellena con ceros «por comodidad».
 *
 * ## El cierre puede pedir PIN
 *
 * Si el valor absoluto de la diferencia pasa el umbral del negocio, cerrar responde 409 pidiendo autorización (D175).
 * Es el mismo contrato que las mermas, con su propio umbral y su propio permiso.
 */
const props = defineProps({
    /** El ULID del conteo, desde la ruta. */
    countUlid: { type: String, required: true },
});

const { can, canWrite } = useAuthorization();

const count = ref(null);
const loading = ref(true);
const error = ref(null);

/** Lo capturado, por clave de renglón. Se separa del recurso para no perder lo escrito al recargar. */
const captured = ref({});
const saving = ref(false);
const saveError = ref(null);
const savedAt = ref(null);

const closing = ref(false);
const closeError = ref(null);
const pendingAuthorization = ref(null);

const puedeVerDiferencias = computed(() => can('inventory.counts.close'));
const puedeCapturar = computed(() => canWrite('inventory.counts.create'));
const puedeCerrar = computed(() => canWrite('inventory.counts.close'));

/** La clave de un renglón: artículo más lote, porque un artículo con lotes tiene un renglón por lote. */
function lineKey(line) {
    return `${line.article.ulid}|${line.lot?.ulid ?? ''}`;
}

onMounted(load);

async function load() {
    loading.value = true;
    error.value = null;

    try {
        count.value = (await api.get(`/stock-counts/${props.countUlid}`)).data;

        captured.value = Object.fromEntries(
            count.value.lines.map((l) => [lineKey(l), l.counted_quantity ?? '']),
        );
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'No se pudo cargar el conteo.';
    } finally {
        loading.value = false;
    }
}

/**
 * Guarda TODOS los renglones, no sólo los cambiados.
 *
 * El endpoint reemplaza la captura completa y exige que la clave `counted_quantity` venga presente aunque sea nula
 * (`present`, `nullable`): así «no contado» es explícito y no el resultado de un renglón que se olvidó de enviar. Mandar
 * un subconjunto haría que un renglón contado ayer desapareciera de la hoja hoy.
 */
async function saveLines() {
    saving.value = true;
    saveError.value = null;

    try {
        const payload = count.value.lines.map((l) => ({
            article_ulid: l.article.ulid,
            lot_ulid: l.lot?.ulid ?? null,
            counted_quantity: captured.value[lineKey(l)] === '' ? null : captured.value[lineKey(l)],
        }));

        count.value = (await api.put(`/stock-counts/${props.countUlid}/lines`, { lines: payload })).data;
        savedAt.value = new Date();
    } catch (e) {
        saveError.value = e instanceof ApiError ? e.message : 'No se pudo guardar la captura.';
    } finally {
        saving.value = false;
    }
}

/**
 * Cierra el conteo, y si el servidor pide firma abre el diálogo del PIN.
 *
 * No se pide la autorización antes: es de un solo uso y se gastaría aunque la diferencia no pasara el umbral.
 */
async function tryClose(authorizationToken = null) {
    closing.value = true;
    closeError.value = null;

    try {
        count.value = (await api.post(`/stock-counts/${props.countUlid}/close`, {
            authorization_token: authorizationToken,
        })).data;

        pendingAuthorization.value = null;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (e.isAuthorizationRequired) {
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message };

            return;
        }

        closeError.value = e.message;
    } finally {
        closing.value = false;
    }
}

async function cancel() {
    if (!window.confirm('¿Cancelar este conteo? La captura se descarta y el inventario queda como estaba.')) {
        return;
    }

    count.value = (await api.post(`/stock-counts/${props.countUlid}/cancel`)).data;
}

const sinContar = computed(() => count.value === null
    ? 0
    : count.value.lines.filter((l) => (captured.value[lineKey(l)] ?? '') === '').length);

/**
 * Las columnas del bloque ciego sólo existen para quien las puede ver.
 *
 * Se omiten en lugar de mostrarse vacías: una columna «Esperado» siempre en blanco delataría que hay un dato oculto, y
 * quien cuenta acabaría buscándolo por otra vía — que es lo que el control evita.
 */
const columns = computed(() => [
    { key: 'article', label: 'Artículo' },
    { key: 'lot', label: 'Lote', width: '8rem' },
    { key: 'counted', label: 'Contado', width: '9rem' },
    ...puedeVerDiferencias.value ? [
        { key: 'expected', label: 'Esperado', width: '8rem' },
        { key: 'variance', label: 'Diferencia', width: '8rem' },
        { key: 'variance_value', label: 'Valor', width: '9rem' },
    ] : [],
    ...count.value !== null && !count.value.is_open ? [{ key: 'adjustment', label: 'Ajuste', width: '7rem' }] : [],
]);

function dinero(valor) {
    return valor === null || valor === undefined
        ? '—'
        : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(valor));
}

function cantidad(valor) {
    return valor === null || valor === undefined ? '—' : Number(valor).toLocaleString('es-MX', { maximumFractionDigits: 4 });
}
</script>

<template>
    <Head :title="count ? `Conteo · ${count.warehouse?.name}` : 'Conteo'" />

    <p v-if="loading" class="state">Cargando…</p>
    <p v-else-if="error" class="alert">{{ error }}</p>

    <template v-else>
        <header class="page-header">
            <div>
                <a href="/admin/conteos" class="link">← Conteos</a>
                <h1>Conteo de {{ count.warehouse?.name }}</h1>
                <p class="page-header__hint">
                    <span class="badge" :class="count.is_open ? 'badge--warn' : 'badge--ok'">{{ count.status_label }}</span>
                    · abrió {{ count.started_by?.name ?? '—' }}
                    <template v-if="count.closed_by"> · cerró {{ count.closed_by.name }}</template>
                    <template v-if="count.authorized_by"> · autorizó {{ count.authorized_by.name }}</template>
                </p>
            </div>

            <div v-if="count.is_open" class="page-header__actions">
                <button v-if="puedeCerrar" type="button" class="link-button" @click="cancel">Cancelar conteo</button>
                <button v-if="puedeCerrar" type="button" class="button" :disabled="closing" @click="tryClose()">
                    Cerrar y ajustar
                </button>
            </div>
        </header>

        <!--
            El aviso del conteo ciego. Se dice EN PANTALLA en lugar de dejar los campos misteriosamente ausentes:
            quien cuenta tiene que entender que no ve lo esperado a propósito, o va a creer que la pantalla está rota y
            va a ir a pedir el dato por otra vía — que es justamente lo que el control evita.
        -->
        <p v-if="count.is_open && !puedeVerDiferencias" class="alert alert--notice">
            Este es un <strong>conteo ciego</strong>: no verás lo que el sistema esperaba encontrar. Cuenta lo que hay y
            captúralo tal cual. Si lo esperado estuviera a la vista, la tentación de confirmarlo haría que el conteo no
            sirviera para lo único que sirve — descubrir la diferencia.
        </p>

        <p v-if="count.is_open && sinContar > 0" class="drawer__hint">
            Quedan <strong>{{ sinContar }}</strong> renglones sin contar. Dejar un renglón vacío no es lo mismo que
            capturar cero: vacío deja el saldo como está, cero lo baja a cero.
        </p>

        <p v-if="saveError" class="alert">{{ saveError }}</p>
        <p v-if="closeError" class="alert">{{ closeError }}</p>

        <DataTable
            :columns="columns"
            :rows="count.lines"
            :loading="false"
            :error="null"
            empty-message="Este conteo no tiene renglones: el almacén no tenía existencias al abrirlo."
        >
            <template #cell:article="{ row }">
                {{ row.article.name }}
                <span class="muted">{{ row.article.base_unit_code }}</span>
            </template>

            <template #cell:lot="{ row }">
                <span v-if="row.lot">{{ row.lot.code }}</span>
                <span v-else class="muted">—</span>
            </template>

            <template #cell:counted="{ row }">
                <input
                    v-if="count.is_open && puedeCapturar"
                    v-model="captured[lineKey(row)]"
                    class="input"
                    inputmode="decimal"
                    placeholder="sin contar"
                />
                <span v-else>{{ cantidad(row.counted_quantity) }}</span>
            </template>

            <template #cell:expected="{ row }">{{ cantidad(row.expected_quantity) }}</template>

            <template #cell:variance="{ row }">
                <span :class="{ 'is-negative': Number(row.variance) < 0 }">{{ cantidad(row.variance) }}</span>
            </template>

            <template #cell:variance_value="{ row }">
                <span :class="{ 'is-negative': Number(row.variance_value) < 0 }">{{ dinero(row.variance_value) }}</span>
            </template>

            <template #cell:adjustment="{ row }">
                <!-- El enlace conteo → kardex: un renglón sin diferencia no generó ajuste, y eso es correcto. -->
                <span v-if="row.adjustment_movement_ulid" class="badge badge--ok">Sí</span>
                <span v-else class="muted">—</span>
            </template>
        </DataTable>

        <div v-if="count.is_open && puedeCapturar" class="page-header__actions page-header__actions--end">
            <span v-if="savedAt" class="muted">Guardado {{ savedAt.toLocaleTimeString('es-MX') }}</span>
            <button type="button" class="button" :disabled="saving" @click="saveLines">
                {{ saving ? 'Guardando…' : 'Guardar captura' }}
            </button>
        </div>

        <p v-if="!count.is_open && count.variance_value !== undefined" class="totals">
            Diferencia total del conteo:
            <strong :class="{ 'is-negative': Number(count.variance_value) < 0 }">{{ dinero(count.variance_value) }}</strong>
            <span class="muted"> · en valor absoluto {{ dinero(count.variance_value_absolute) }}</span>
        </p>

        <PinAuthorizationDialog
            v-if="pendingAuthorization"
            :required-permission="pendingAuthorization.permission"
            :reason="pendingAuthorization.reason"
            @granted="(token) => tryClose(token)"
            @cancelled="pendingAuthorization = null"
        />
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

.page-header__actions--end {
    justify-content: flex-end;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.totals {
    margin-top: 1rem;
    font-size: 0.95rem;
}
</style>
