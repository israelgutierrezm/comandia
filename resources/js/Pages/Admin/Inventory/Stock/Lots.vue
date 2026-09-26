<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../../api/client';
import { useApiForm } from '../../../../stores/useResourceList';
import { pushToast } from '../../../../stores/useToasts';
import { useAuthorization } from '../../../../composables/useAuthorization';
import DataTable from '../../../../components/DataTable.vue';
import FormHeader from '../../../../components/FormHeader.vue';
import ListHeader from '../../../../components/ListHeader.vue';
import Icon from '../../../../components/Icon.vue';
import { formatCalendarDate, formatQuantity, todayInZone } from '../../../../components/inventory/inventoryFormat';

/**
 * Lotes y caducidades de un artículo (D23, D165, D166).
 *
 * ## Por qué existe si la salida elige sola
 *
 * FEFO es automático: quien registra una salida dice cuánto, no de dónde (§6.2). Lo que no puede ser automático son dos
 * decisiones que sólo toma una persona: **corregir una caducidad mal tecleada** —que cambia el orden en que sale lo que
 * queda— y **dar un lote por caducado**. Las dos viven aquí. Los lotes nacen casi siempre en la recepción de compra; aquí
 * se dan de alta los que llegan por otra vía.
 *
 * ## Dos listas, y por qué
 *
 * «En surtido» son los activos, en el orden en que van a salir: primero lo que caduca, los que no caducan al final. Es el
 * orden del servidor y no se reordena aquí: así la pantalla dice sin más explicación de dónde sale lo siguiente.
 *
 * «Fuera de surtido» son los caducados o agotados que todavía tienen saldo en algún almacén. Salen de las existencias del
 * artículo y no del listado de lotes, porque ese endpoint sólo devuelve los activos (su filtro de estado se suma a la
 * condición de activo, así que pedir los caducados regresa vacío). Son justo los que importan: mercancía que la salida
 * automática ya no toma y que alguien tiene que dar de baja en Mermas, ajustar, o reactivar si se salva.
 *
 * ## Caducar no es mermar
 *
 * Marcar un lote caducado sólo lo saca del surtido: su existencia sigue ahí (D166). Dar la mercancía por perdida en
 * automático convertiría un vencimiento de calendario en una pérdida que nadie revisó. La confirmación lo dice así, con
 * la existencia que se queda.
 */
const props = defineProps({
    /** El ULID del artículo, desde la ruta. */
    articleUlid: { type: String, required: true },
});

const page = usePage();
const { can, canWrite } = useAuthorization();
const uid = useId();

const zona = computed(() => page.props.context?.branch_timezone ?? null);
const hoy = computed(() => todayInZone(zona.value));

const article = ref(null);
const lots = ref([]);
const stocks = ref([]);

const loadingLots = ref(true);

/** El error del listado de lotes, como `ApiError`: la tabla distingue el 403 de lo demás. */
const lotsError = ref(null);
const articleError = ref(null);
const stocksError = ref(null);

/** `false` mientras la existencia no se haya podido leer: la columna no puede afirmar «sin existencia». */
const stocksLoaded = ref(false);

/** El error de una acción de renglón (marcar caducado). */
const actionError = ref(null);

onMounted(() => Promise.all([loadArticle(), loadLots(), loadStocks()]));

async function loadArticle() {
    try {
        article.value = (await api.get(`/articles/${props.articleUlid}`)).data;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // Sin permiso de catálogo, el nombre sale de las existencias; y si el artículo no existe, ya lo dice la tabla.
        if (e.status !== 403 && e.status !== 404) {
            articleError.value = e.message;
        }
    }
}

async function loadLots() {
    loadingLots.value = true;
    lotsError.value = null;

    try {
        lots.value = (await api.get(`/articles/${props.articleUlid}/lots`)).data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        lots.value = [];
        lotsError.value = e;
    } finally {
        loadingLots.value = false;
    }
}

async function loadStocks() {
    stocksError.value = null;

    try {
        stocks.value = (await api.get(`/articles/${props.articleUlid}/stock`)).data ?? [];
        stocksLoaded.value = true;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        stocks.value = [];
        stocksLoaded.value = false;

        // El 403 y el 404 ya los dice la tabla de lotes: mismo permiso, mismo artículo.
        if (e.status !== 403 && e.status !== 404) {
            stocksError.value = e.message;
        }
    }
}

const nombre = computed(() => article.value?.name ?? stocks.value[0]?.article?.name ?? null);
const unidad = computed(() => article.value?.base_unit?.code ?? stocks.value[0]?.article?.base_unit_code ?? '');

/** Sólo se afirma cuando la ficha lo dice: sin permiso de catálogo no se sabe, y el servidor rechazaría el alta igual. */
const noLlevaLotes = computed(() => article.value?.tracks_lots === false);

const puedeAdministrar = computed(() => canWrite('inventory.lots.manage'));

/** Los saldos de un lote, uno por almacén. Tal como los manda el servidor: aquí no se suma nada. */
function saldosDe(loteUlid) {
    return stocks.value.filter((fila) => fila.lot?.ulid === loteUlid);
}

/** «Central: 5 kg; Polanco: 2 kg», sólo con los saldos distintos de cero. Vacío si no le queda nada. */
function describirSaldo(loteUlid) {
    return saldosDe(loteUlid)
        .filter((fila) => Number(fila.quantity) !== 0)
        .map((fila) => `${fila.warehouse?.name ?? '—'}: ${[formatQuantity(fila.quantity), unidad.value].filter(Boolean).join(' ')}`)
        .join('; ');
}

/**
 * Los caducados o agotados que todavía tienen saldo, uno por lote.
 *
 * La etiqueta de su estado es la única copia en el cliente: el saldo trae el código (`status`) y no el texto.
 */
const ESTADOS = { expired: { label: 'Caducado', badge: 'warn' }, depleted: { label: 'Agotado', badge: 'off' } };

const fueraDeSurtido = computed(() => {
    const vistos = new Map();

    for (const fila of stocks.value) {
        const lote = fila.lot;

        if (lote && lote.status !== 'active' && Number(fila.quantity) !== 0 && !vistos.has(lote.ulid)) {
            vistos.set(lote.ulid, lote);
        }
    }

    return [...vistos.values()];
});

/** La existencia que no está en ningún lote: la que entró sin lote, o el faltante de una salida que los lotes no cubrieron. */
const sinLote = computed(() => stocks.value.filter((fila) => fila.lot === null && Number(fila.quantity) !== 0));

const columnasSurtido = computed(() => [
    { key: 'code', label: 'Lote' },
    { key: 'expires_at', label: 'Caducidad', width: '12rem' },
    { key: 'received_at', label: 'Recibido', width: '9rem' },
    { key: 'stock', label: 'Existencia' },
    ...(puedeAdministrar.value ? [{ key: 'actions', label: '', width: '15rem' }] : []),
]);

const columnasFuera = computed(() => [
    { key: 'code', label: 'Lote' },
    { key: 'expires_at', label: 'Caducidad', width: '12rem' },
    { key: 'status', label: 'Estado', width: '8rem' },
    { key: 'stock', label: 'Existencia' },
    ...(puedeAdministrar.value ? [{ key: 'actions', label: '', width: '8rem' }] : []),
]);

// -----------------------------------------------------------------------------------------------------------------
// Alta
// -----------------------------------------------------------------------------------------------------------------

const creando = ref(false);
const nuevo = ref({ code: '', received_at: '', expires_at: '' });

const alta = useApiForm(async () => (await api.post(`/articles/${props.articleUlid}/lots`, {
    code: nuevo.value.code.trim(),
    // Vacía = no caduca, y se manda `null` explícito: una fecha lejana sería inventar un dato que alguien leería como real.
    expires_at: nuevo.value.expires_at || null,
    received_at: nuevo.value.received_at,
})).data, { success: { kind: 'create', entity: 'Lote', gender: 'm' } });

function abrirAlta() {
    nuevo.value = { code: '', received_at: hoy.value, expires_at: '' };
    alta.fieldErrors.value = {};
    alta.generalError.value = null;
    creando.value = true;
}

async function enviarAlta() {
    if (await alta.submit()) {
        creando.value = false;
        await loadLots();
    }
}

// -----------------------------------------------------------------------------------------------------------------
// Edición: caducidad y estado (el código y el artículo no se tocan, D96)
// -----------------------------------------------------------------------------------------------------------------

const editando = ref(null);
const edicion = ref({ expires_at: '', status: 'active' });

/** Sólo lo que cambió: el servidor acepta cada campo por separado (`sometimes`), y mandar lo que no cambió no dice nada. */
const cambiosEdicion = computed(() => {
    const lote = editando.value;

    if (!lote) {
        return {};
    }

    const cambios = {};
    const caducidad = edicion.value.expires_at || null;

    if (caducidad !== (lote.expires_at ?? null)) {
        cambios.expires_at = caducidad;
    }

    if (edicion.value.status !== lote.status) {
        cambios.status = edicion.value.status;
    }

    return cambios;
});

const hayCambios = computed(() => Object.keys(cambiosEdicion.value).length > 0);

const guardarEdicion = useApiForm(
    async () => (await api.patch(`/lots/${editando.value.ulid}`, cambiosEdicion.value)).data,
    { success: { kind: 'update', entity: 'Lote', gender: 'm' } },
);

function abrirEdicion(lote) {
    editando.value = lote;
    edicion.value = { expires_at: lote.expires_at ?? '', status: lote.status };
    guardarEdicion.fieldErrors.value = {};
    guardarEdicion.generalError.value = null;
}

async function enviarEdicion() {
    if (!hayCambios.value) {
        return;
    }

    if (await guardarEdicion.submit()) {
        editando.value = null;

        // El estado mueve el lote entre las dos listas, y la de fuera de surtido sale de las existencias.
        await Promise.all([loadLots(), loadStocks()]);
    }
}

/** Lo que de verdad pasa al cambiar el estado, dicho antes de guardar. */
const consecuenciaEstado = computed(() => {
    const lote = editando.value;

    if (!lote || edicion.value.status === lote.status) {
        return null;
    }

    if (edicion.value.status === 'active') {
        return 'Vuelve a surtir: la salida automática lo tomará cuando le toque por su caducidad. Si su caducidad ya pasó, '
            + 'saldrá antes que todo lo demás; corrígela arriba si estaba mal tecleada.';
    }

    const saldo = describirSaldo(lote.ulid);

    if (saldo) {
        return `Deja de surtir y todavía tiene existencia (${saldo}): se queda en el lote y la salida automática ya no la `
            + 'tomará; lo que falte se cargará a la existencia sin lote. Márcalo agotado sólo si ya no queda nada de él.';
    }

    return 'Deja de surtir: la salida automática ya no lo tomará.';
});

// -----------------------------------------------------------------------------------------------------------------
// Marcar caducado
// -----------------------------------------------------------------------------------------------------------------

/** El ULID del lote en proceso: bloquea los botones para no mandar la misma acción dos veces. */
const trabajando = ref(null);

async function marcarCaducado(lote) {
    if (trabajando.value !== null) {
        return;
    }

    const saldo = describirSaldo(lote.ulid);

    let existencia;

    if (!stocksLoaded.value) {
        existencia = '• NO se registra ninguna merma: si tiene existencia, se queda en el lote hasta que alguien la '
            + 'registre en Mermas eligiendo este lote.';
    } else if (saldo) {
        existencia = `• NO se registra ninguna merma: su existencia (${saldo}) se queda en el lote. Para darla de baja, `
            + 'regístrala en Mermas eligiendo este lote; si se salva, puedes reactivarlo.';
    } else {
        existencia = '• No se registra ninguna merma. Este lote no tiene existencia registrada.';
    }

    const mensaje = [
        `¿Marcar el lote ${lote.code} como caducado?`,
        '',
        '• Deja de surtir: la salida automática (primero lo que caduca) ya no lo tomará.',
        existencia,
    ].join('\n');

    if (!window.confirm(mensaje)) {
        return;
    }

    trabajando.value = lote.ulid;
    actionError.value = null;

    try {
        await api.post(`/lots/${lote.ulid}/expire`);
        pushToast(`Lote ${lote.code} marcado como caducado.`, 'warning');

        await Promise.all([loadLots(), loadStocks()]);
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        actionError.value = e.message;
    } finally {
        trabajando.value = null;
    }
}
</script>

<template>
    <Head :title="nombre ? `Lotes · ${nombre}` : 'Lotes'" />

    <p class="breadcrumb">
        <Link href="/admin/existencias" class="link-button">← Existencias</Link>
        <Link
            v-if="can('inventory.kardex.view')"
            :href="`/admin/existencias/${props.articleUlid}/kardex`"
            class="link-button"
        ><Icon name="eye" /> Kardex</Link>
    </p>

    <ListHeader
        :title="nombre ? `Lotes de ${nombre}` : 'Lotes'"
        subtitle="La salida elige sola: primero lo que caduca antes, y los que no caducan al final. Aquí se corrige una caducidad mal tecleada y se da un lote por caducado, que son decisiones de una persona y no del calendario."
    >
        <template #action>
            <button
                v-if="puedeAdministrar"
                type="button"
                class="button"
                :disabled="noLlevaLotes"
                :title="noLlevaLotes ? 'Este artículo no se controla por lotes.' : ''"
                @click="abrirAlta"
            ><Icon name="plus" /> Nuevo lote</button>
        </template>
    </ListHeader>

    <p v-if="noLlevaLotes" class="alert alert--notice">
        <strong>{{ nombre }}</strong> no se controla por lotes, así que no se le pueden dar de alta: la salida automática
        no los usaría. Si su caducidad importa, actívale el control de lotes en su ficha del catálogo.
    </p>

    <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
    <p v-if="articleError" class="alert" role="alert">No se pudo cargar la ficha del artículo: {{ articleError }}</p>
    <p v-if="stocksError" class="alert" role="alert">
        No se pudo cargar la existencia por almacén, así que la columna «Existencia» no dice nada: {{ stocksError }}
    </p>

    <section class="bloque">
        <h2 class="bloque__titulo">En surtido, en el orden en que salen</h2>

        <DataTable
            :columns="columnasSurtido"
            :rows="lots"
            :loading="loadingLots"
            :error="lotsError"
            :empty-message="noLlevaLotes
                ? 'Este artículo no se controla por lotes.'
                : 'No tiene lotes en surtido. Nacen al recibir una compra con su código, o aquí con «Nuevo lote».'"
        >
            <template #cell:code="{ row }">
                <strong>{{ row.code }}</strong>
            </template>

            <template #cell:expires_at="{ row }">
                <span v-if="row.expires_at">{{ formatCalendarDate(row.expires_at) }}</span>
                <span v-else class="muted">no caduca</span>
                <!--
                    Activo con la caducidad ya pasada: la salida automática lo toma PRIMERO. Lo calcula el servidor, que
                    es el que tiene el reloj confiable, no el navegador.
                -->
                <span
                    v-if="row.is_expired"
                    class="badge badge--warn vencido"
                    title="Ya pasó su caducidad y sigue en surtido: la salida automática lo toma primero. Márcalo caducado si ya no sirve."
                >ya venció</span>
            </template>

            <template #cell:received_at="{ row }">{{ formatCalendarDate(row.received_at) || '—' }}</template>

            <template #cell:stock="{ row }">
                <span v-if="!stocksLoaded" class="muted">—</span>
                <span v-else-if="saldosDe(row.ulid).length === 0" class="muted">sin existencia registrada</span>
                <ul v-else class="saldos">
                    <li v-for="fila in saldosDe(row.ulid)" :key="fila.warehouse?.ulid">
                        <span class="muted">{{ fila.warehouse?.name ?? '—' }}:</span>
                        <strong :class="{ 'value--negative': fila.is_negative }">
                            {{ formatQuantity(fila.quantity) }} {{ unidad }}
                        </strong>
                    </li>
                </ul>
            </template>

            <template #cell:actions="{ row }">
                <div class="row-actions">
                    <button
                        type="button"
                        class="link-button link-button--warning"
                        :disabled="trabajando !== null"
                        @click="abrirEdicion(row)"
                    ><Icon name="edit" /> Editar</button>
                    <button
                        type="button"
                        class="link-button link-button--danger"
                        :disabled="trabajando !== null"
                        @click="marcarCaducado(row)"
                    ><Icon name="x" /> {{ trabajando === row.ulid ? 'Marcando…' : 'Marcar caducado' }}</button>
                </div>
            </template>
        </DataTable>
    </section>

    <section v-if="fueraDeSurtido.length > 0" class="bloque">
        <h2 class="bloque__titulo">Fuera de surtido, con existencia</h2>
        <p class="bloque__pista">
            Caducados o agotados que todavía tienen saldo. La salida automática ya no los toma: esa mercancía se da de baja
            en <Link v-if="can('inventory.waste.create')" href="/admin/mermas" class="enlace">Mermas</Link><template v-else>Mermas</template>
            eligiendo el lote, se ajusta con un movimiento, o —si se salva— se reactiva el lote.
        </p>

        <DataTable :columns="columnasFuera" :rows="fueraDeSurtido" :loading="false" :error="null">
            <template #cell:code="{ row }">
                <strong>{{ row.code }}</strong>
            </template>

            <template #cell:expires_at="{ row }">
                <span v-if="row.expires_at">{{ formatCalendarDate(row.expires_at) }}</span>
                <span v-else class="muted">no caduca</span>
            </template>

            <template #cell:status="{ row }">
                <span class="badge" :class="`badge--${ESTADOS[row.status]?.badge ?? 'off'}`">
                    {{ ESTADOS[row.status]?.label ?? row.status }}
                </span>
            </template>

            <template #cell:stock="{ row }">
                <ul class="saldos">
                    <li v-for="fila in saldosDe(row.ulid)" :key="fila.warehouse?.ulid">
                        <span class="muted">{{ fila.warehouse?.name ?? '—' }}:</span>
                        <strong :class="{ 'value--negative': fila.is_negative }">
                            {{ formatQuantity(fila.quantity) }} {{ unidad }}
                        </strong>
                    </li>
                </ul>
            </template>

            <template #cell:actions="{ row }">
                <button
                    type="button"
                    class="link-button link-button--warning"
                    :disabled="trabajando !== null"
                    @click="abrirEdicion(row)"
                ><Icon name="edit" /> Editar</button>
            </template>
        </DataTable>
    </section>

    <section v-if="!noLlevaLotes && sinLote.length > 0" class="bloque">
        <h2 class="bloque__titulo">Existencia sin lote</h2>
        <p class="bloque__pista">
            Lo que entró sin capturar lote, o lo que salió cuando los lotes no alcanzaron. La salida automática la toma sólo
            cuando se acaban los lotes, y un saldo negativo aquí es mercancía que el sistema no supo atribuir: lo primero
            que el próximo conteo debe revisar.
        </p>
        <ul class="saldos saldos--bloque">
            <li v-for="fila in sinLote" :key="fila.warehouse?.ulid">
                <span class="muted">{{ fila.warehouse?.name ?? '—' }}:</span>
                <strong :class="{ 'value--negative': fila.is_negative }">{{ formatQuantity(fila.quantity) }} {{ unidad }}</strong>
            </li>
        </ul>
    </section>

    <!-- Alta de un lote -->
    <div v-if="creando" class="drawer-backdrop" @click.self="creando = false">
        <form class="drawer" @submit.prevent="enviarAlta">
            <FormHeader title="Nuevo lote" :subtitle="nombre ?? ''" />

            <p class="drawer__hint">
                Casi todos los lotes nacen solos al recibir una compra con su código. Da de alta aquí los que llegan por
                otra vía —una entrada manual, la carga inicial— para poder elegirlos al registrarla.
            </p>

            <p v-if="alta.generalError.value" class="alert" role="alert">{{ alta.generalError.value }}</p>

            <div class="field">
                <label class="field__label" :for="`${uid}-alta-codigo`">Código</label>
                <input
                    :id="`${uid}-alta-codigo`"
                    v-model="nuevo.code"
                    class="input"
                    maxlength="40"
                    autocomplete="off"
                    required
                />
                <span class="field__hint">
                    Tal como viene impreso en la caja: letras, números, guion, punto y diagonal. No se cambia después,
                    porque los movimientos lo citan.
                </span>
                <span v-if="alta.fieldErrors.value.code" class="field__error">{{ alta.fieldErrors.value.code }}</span>
            </div>

            <div class="field">
                <label class="field__label" :for="`${uid}-alta-recibido`">Recibido</label>
                <input
                    :id="`${uid}-alta-recibido`"
                    v-model="nuevo.received_at"
                    type="date"
                    class="input"
                    :max="hoy"
                    required
                />
                <span v-if="alta.fieldErrors.value.received_at" class="field__error">
                    {{ alta.fieldErrors.value.received_at }}
                </span>
            </div>

            <div class="field">
                <label class="field__label" :for="`${uid}-alta-caducidad`">
                    Caducidad <span class="muted">opcional</span>
                </label>
                <input
                    :id="`${uid}-alta-caducidad`"
                    v-model="nuevo.expires_at"
                    type="date"
                    class="input"
                    :min="nuevo.received_at || undefined"
                />
                <span class="field__hint">
                    Vacía si no caduca (la sal, el azúcar): una fecha lejana inventaría un dato que alguien leería como real.
                    No puede ser anterior a la recepción.
                </span>
                <span v-if="alta.fieldErrors.value.expires_at" class="field__error">
                    {{ alta.fieldErrors.value.expires_at }}
                </span>
            </div>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="creando = false"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="alta.processing.value">
                    <Icon name="plus" /> {{ alta.processing.value ? 'Creando…' : 'Crear lote' }}
                </button>
            </div>
        </form>
    </div>

    <!-- Edición: caducidad y estado -->
    <div v-if="editando" class="drawer-backdrop" @click.self="editando = null">
        <form class="drawer" @submit.prevent="enviarEdicion">
            <FormHeader :title="`Lote ${editando.code}`" :subtitle="nombre ?? ''" />

            <p class="drawer__hint">
                El código y el artículo no cambian: los movimientos de inventario ya citan este lote, y reasignarlo
                reinterpretaría existencias que ya se movieron. Si el código está mal, crea otro lote.
            </p>

            <p v-if="guardarEdicion.generalError.value" class="alert" role="alert">
                {{ guardarEdicion.generalError.value }}
            </p>

            <div class="field">
                <label class="field__label" :for="`${uid}-editar-caducidad`">Caducidad</label>
                <input
                    :id="`${uid}-editar-caducidad`"
                    v-model="edicion.expires_at"
                    type="date"
                    class="input"
                    :min="editando.received_at || undefined"
                />
                <span class="field__hint">
                    Vacía = no caduca. Corregirla no cambia nada de lo que ya salió: sólo el orden en que sale lo que queda.
                </span>
                <span v-if="guardarEdicion.fieldErrors.value.expires_at" class="field__error">
                    {{ guardarEdicion.fieldErrors.value.expires_at }}
                </span>
            </div>

            <div class="field">
                <label class="field__label" :for="`${uid}-editar-estado`">Estado</label>
                <select :id="`${uid}-editar-estado`" v-model="edicion.status" class="input">
                    <option value="active">Activo: surte por caducidad</option>
                    <option value="depleted">Agotado: ya no surte</option>
                    <option value="expired" :disabled="editando.status !== 'expired'">Caducado: ya no surte</option>
                </select>
                <span v-if="editando.status !== 'expired'" class="field__hint">
                    Para darlo por caducado usa «Marcar caducado» en su renglón: es una decisión aparte, y así se dice antes
                    qué pasa con su existencia.
                </span>
                <span v-if="guardarEdicion.fieldErrors.value.status" class="field__error">
                    {{ guardarEdicion.fieldErrors.value.status }}
                </span>
            </div>

            <p v-if="consecuenciaEstado" class="alert alert--notice">{{ consecuenciaEstado }}</p>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="editando = null"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="guardarEdicion.processing.value || !hayCambios">
                    <Icon name="check" /> {{ guardarEdicion.processing.value ? 'Guardando…' : 'Guardar' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../../../css/admin-page.css';

.breadcrumb {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 0 0 0.35rem;
    font-size: 0.85rem;
}

.bloque {
    margin-bottom: 1.5rem;
}

.bloque__titulo {
    margin: 0 0 0.5rem;
    font-size: 0.95rem;
    font-weight: 600;
}

.bloque__pista {
    margin: 0 0 0.75rem;
    max-width: 46rem;
    color: var(--color-suave);
    font-size: 0.85rem;
    line-height: 1.5;
}

.enlace {
    color: var(--color-acento);
    text-decoration: underline;
}

.drawer__hint {
    margin: 0 0 0.9rem;
    color: var(--color-suave);
    font-size: 0.85rem;
}

.muted {
    color: var(--color-suave);
    font-size: 0.85rem;
    font-weight: 400;
}

.vencido {
    margin-left: 0.35rem;
}

.saldos {
    margin: 0;
    padding: 0;
    list-style: none;
}

.saldos li + li {
    margin-top: 0.15rem;
}

.saldos--bloque li {
    font-size: 0.9rem;
}

.value--negative {
    color: var(--color-peligro);
    font-weight: 600;
}
</style>
