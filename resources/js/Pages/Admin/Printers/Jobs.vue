<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { api, ApiError, orEmptyWhenForbidden } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatInBranchTime } from '../../../support/datetime';
import { formatMoney } from '../../../support/money';
import DataTable from '../../../components/DataTable.vue';
import FormHeader from '../../../components/FormHeader.vue';
import ListHeader from '../../../components/ListHeader.vue';
import Paginacion from '../../../components/Paginacion.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Trabajos de impresión: qué se mandó a imprimir, qué salió y qué falló (módulo Printing).
 *
 * ## Por qué existe
 *
 * Un fallo de impresión NO tumba la venta (D246): quien encola va envuelto y el agente reporta el fallo sin que nadie en
 * la caja se entere. La contrapartida es que el error no llega a la cara de quien opera, así que tiene que llegar a algún
 * sitio donde alguien lo vea. Es éste: sin él, «la cocina no recibió la comanda» no tenía dónde mirarse.
 *
 * ## Qué se ve primero
 *
 * Lo que todavía puede necesitar a alguien —pendientes, tomados por un agente y fallidos—, que es `only_open`, la vista
 * con la que el servidor dice que abre esta pantalla. Lo impreso hace dos horas no le sirve a quien busca por qué la
 * cocina no recibe papeles; queda a un clic en «Impresos» o «Todos».
 *
 * ## Reintentar lo decide una persona, y QUÉ se puede reintentar lo dice el servidor
 *
 * Un fallo no se reintenta solo, a propósito: veinte reintentos automáticos serían veinte comandas saliendo juntas cuando
 * alguien pusiera papel. Aquí se reintenta a mano, y la pantalla no calcula cuándo se puede: lo dice `allowed_next`, la
 * máquina de estados del servidor (vuelve a `pending` desde `failed` y desde `claimed`). Desde `claimed` se llama
 * «Liberar»: es el agente que tomó el papel y dejó de responder; si en realidad lo sigue imprimiendo, el papel sale dos
 * veces, y por eso se pregunta antes.
 *
 * ## Al día sola, sin martillar
 *
 * Cada 15 s mientras la pestaña está a la vista, y en silencio: la tabla no se vacía en cada vuelta. Con la pestaña oculta
 * no se pide nada (`document.hidden`); al volver se pide en el momento si lo que se ve ya tiene un ciclo encima. Un error
 * del cliente (403, 422) detiene el refresco: repetir la misma petición cada 15 s no lo arregla.
 */
const REFRESH_MS = 15000;

/**
 * Las vistas por estado. Cada una es la consulta tal como la acepta la lista blanca del servidor: `only_open` o `status`,
 * nunca los dos, porque se intersectarían —«sin imprimir» y «impresos» juntos darían siempre vacío—.
 */
const VIEWS = [
    { value: 'open', label: 'Sin imprimir', query: { only_open: 1 } },
    { value: 'failed', label: 'Fallidos', query: { status: 'failed' } },
    { value: 'pending', label: 'Pendientes', query: { status: 'pending' } },
    { value: 'claimed', label: 'Tomados', query: { status: 'claimed' } },
    { value: 'printed', label: 'Impresos', query: { status: 'printed' } },
    { value: 'cancelled', label: 'Cancelados', query: { status: 'cancelled' } },
    { value: 'all', label: 'Todos', query: {} },
];

/**
 * El color de cada estado: rojo lo que falló y pide una mano, ámbar lo que todavía no sale, verde lo impreso y gris lo
 * cancelado. `admin-page.css` sólo trae ok/off/warn: el rojo es local (`badge--fallo`) y sale de los tokens de peligro.
 */
const STATUS_BADGES = {
    failed: 'badge--fallo',
    pending: 'badge--warn',
    claimed: 'badge--warn',
    printed: 'badge--ok',
    cancelled: 'badge--off',
};

/** Qué conviene hacer con un trabajo según su estado. Es orientación para quien lo mira, no una regla. */
const STATUS_HINTS = {
    pending: 'Está en la cola: saldrá cuando un agente de su sucursal lo tome. Si lleva rato así, revisa que haya un agente en línea.',
    claimed: 'Un agente lo tomó y todavía no reporta si salió. Si ese agente dejó de responder, libéralo para que otro lo tome.',
    failed: 'El agente no pudo imprimirlo y no se reintenta solo. Corrige la causa —papel, energía, red— y reintenta.',
};

const page = usePage();
const { can, canWrite } = useAuthorization();

const filters = reactive({ view: 'open', printer: '', branch: '', kind: '', page: 1 });

const filtrosActivos = computed(
    () => [filters.printer, filters.branch, filters.kind].filter((valor) => valor !== '').length,
);

function limpiarFiltros() {
    filters.printer = '';
    filters.branch = '';
    filters.kind = '';
}

// -----------------------------------------------------------------
// La lista
//
// Sin `useResourceList` a propósito: su `loading` vacía la tabla (DataTable no pinta filas mientras carga), y con un
// refresco cada 15 s la lista parpadearía en blanco cuatro veces por minuto. Aquí hay dos cargas: la VISIBLE —al abrir,
// al filtrar, al cambiar de página, cuando lo que se ve cambia de conjunto— y la SILENCIOSA —refrescar lo mismo—, que
// reemplaza las filas sólo cuando llega la respuesta.
// -----------------------------------------------------------------
const jobs = ref([]);
const meta = ref({});
const loading = ref(false);
const refreshing = ref(false);
/** @type {import('vue').Ref<ApiError|null>} */
const error = ref(null);
const refreshNotice = ref(null);
const updatedAt = ref(null);
const failedTotal = ref(null);

/**
 * Cada carga toma un turno, y sólo la ÚLTIMA pinta. Sin esto, un refresco que salió antes de un cambio de filtro podía
 * llegar después y pintar la lista vieja encima de la nueva — o deshacer a la vista un reintento recién hecho.
 */
let turnSequence = 0;

function commonFilters() {
    return { printer: filters.printer, branch: filters.branch, kind: filters.kind };
}

function listQuery() {
    const view = VIEWS.find((v) => v.value === filters.view) ?? VIEWS[0];

    return { ...view.query, ...commonFilters(), page: filters.page };
}

/**
 * Cuántos fallidos hay con los mismos filtros, para la pastilla «Fallidos»: con muchos pendientes por delante, un fallo
 * puede quedar en la página dos, y la cuenta lo dice sin tener que ir a buscarlo. Es una consulta de una fila.
 */
async function countFailed() {
    // En la vista de fallidos, el total de la lista ya es la cuenta.
    if (filters.view === 'failed') {
        return null;
    }

    try {
        const response = await api.get('/print-jobs', { ...commonFilters(), status: 'failed', per_page: 1 });

        return Number(response?.meta?.total ?? 0);
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // Mismo endpoint y mismo permiso que la lista: si esto falla, falla también la lista y su error se pinta ahí.
        return null;
    }
}

async function load({ silent = false } = {}) {
    const turn = ++turnSequence;

    if (silent) {
        refreshing.value = true;
    } else {
        loading.value = true;
    }

    try {
        const [response, failed] = await Promise.all([api.get('/print-jobs', listQuery()), countFailed()]);

        if (turn !== turnSequence) {
            return;
        }

        jobs.value = response?.data ?? [];
        meta.value = response?.meta ?? {};
        failedTotal.value = failed;
        error.value = null;
        refreshNotice.value = null;
        updatedAt.value = new Date();

        // La página pedida se quedó sin filas —se imprimió o se reintentó lo que había en ella—: se baja a la última que
        // existe. Sin esto la tabla quedaba vacía y SIN paginación, varada en una página sin camino de regreso.
        const lastPage = Math.max(1, Number(meta.value.last_page ?? 1));

        if (jobs.value.length === 0 && filters.page > lastPage) {
            filters.page = lastPage;
        }
    } catch (e) {
        if (turn === turnSequence) {
            const failure = e instanceof ApiError
                ? e
                : new ApiError({
                    type: 'network_error',
                    title: 'No se pudo completar la consulta (¿sin conexión?). Si sigue pasando, recarga la página.',
                    status: 0,
                });

            if (silent && jobs.value.length > 0 && error.value === null) {
                // Un refresco en silencio que falla con la tabla llena conserva lo último que llegó y avisa de cuándo es:
                // vaciarla por un parpadeo de la red escondería justo los fallos que se vienen a ver.
                refreshNotice.value = failure.title;
            } else {
                error.value = failure;
                jobs.value = [];
                meta.value = {};
            }
        }

        if (!(e instanceof ApiError)) {
            throw e;
        }
    } finally {
        if (turn === turnSequence) {
            loading.value = false;
            refreshing.value = false;
        }
    }
}

function refresh() {
    load({ silent: true });
}

// Cambiar lo que se ve vuelve a la primera página; si ya estaba en ella, carga aquí. Si no, el cambio de página dispara
// la carga — una sola, en lugar de dos seguidas.
watch(
    () => [filters.view, filters.printer, filters.branch, filters.kind],
    () => {
        if (filters.page !== 1) {
            filters.page = 1;

            return;
        }

        load();
    },
);

watch(() => filters.page, () => load());

// -----------------------------------------------------------------
// Impresoras y sucursales: filtros y hora local
// -----------------------------------------------------------------
const printers = ref([]);
const branches = ref([]);
const lookupError = ref(null);

async function loadLookups() {
    // Alimentan los filtros y la hora local de cada trabajo, no la lista. Son de otro permiso (`organization.*`): un cajero
    // ve los trabajos sin ver la configuración de impresoras, y ese 403 deja los filtros vacíos en lugar de tumbar la
    // pantalla. Cualquier otro fallo se dice. Las impresoras van TODAS, también las de baja: sus trabajos siguen aquí.
    try {
        const [printerList, branchList] = await Promise.all([
            orEmptyWhenForbidden(api.get('/printers', { per_page: 100 })),
            orEmptyWhenForbidden(api.get('/branches', { status: 'active', per_page: 100 })),
        ]);

        printers.value = printerList.data ?? [];
        branches.value = branchList.data ?? [];
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        lookupError.value = e.title;
    }
}

const branchByPrinter = computed(() => new Map(printers.value.map((p) => [p.ulid, p.branch ?? null])));
const timezoneByBranch = computed(() => new Map(branches.value.map((b) => [b.ulid, b.timezone])));

const manyBranches = computed(
    () => branches.value.length > 1 || new Set(printers.value.map((p) => p.branch?.ulid).filter(Boolean)).size > 1,
);

/**
 * La sucursal del trabajo, deducida de su impresora: el recurso no la trae, y un trabajo siempre sale por una impresora de
 * su propia sucursal (la del área, la de la caja o la del cajón). Sin permiso de ver impresoras, no se sabe.
 */
function branchOf(job) {
    return branchByPrinter.value.get(job?.printer?.ulid) ?? null;
}

/**
 * Fecha corta en la hora de la sucursal DEL TRABAJO, no la del navegador: la lista cruza sucursales, y leer «falló a las
 * 10:05» con el reloj de otra zona engaña justo cuando se investiga qué pasó. Si no se sabe su sucursal, la activa.
 */
function fecha(iso, job) {
    if (!iso) {
        return '—';
    }

    const zona = timezoneByBranch.value.get(branchOf(job)?.ulid) ?? page.props.context?.branch_timezone;

    return formatInBranchTime(iso, zona) || '—';
}

/** La hora del último refresco, con segundos para que se vea avanzar, en la zona de la sucursal activa. */
function clock(date) {
    if (!date) {
        return '';
    }

    const zona = page.props.context?.branch_timezone;

    return date.toLocaleTimeString('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        ...(zona ? { timeZone: zona } : {}),
    });
}

function printerOption(printer) {
    const texto = manyBranches.value && printer.branch?.name ? `${printer.name} · ${printer.branch.name}` : printer.name;

    return printer.status === 'active' ? texto : `${texto} (de baja)`;
}

// -----------------------------------------------------------------
// Qué dice cada papel
// -----------------------------------------------------------------

/** «Mesa 4 (C-102)»: cómo se identifica la cuenta —o el pedido de la tienda— ante quien lo mira, y su folio. */
function accountText(job) {
    const account = job?.payload?.account ?? {};

    if (account.display_name && account.folio && account.folio !== account.display_name) {
        return `${account.display_name} (${account.folio})`;
    }

    return account.display_name ?? account.folio ?? null;
}

/**
 * Qué papel es, dicho como lo diría la cocina: «Comanda» y «Cocina · Mesa 4 (C-102)».
 *
 * Sale del `payload`, que es lo que el agente imprime y quedó CONGELADO al encolar (D247): describe el papel tal como se
 * mandó, aunque la cuenta haya cambiado después. Se lee con cuidado —cualquier campo puede faltar— porque su forma es el
 * contrato del agente y no de esta pantalla; si algo no viene, se cae a la etiqueta del tipo que da el servidor.
 */
function describe(job) {
    const payload = job?.payload ?? {};

    if (job?.kind === 'drawer_open') {
        return {
            title: job.kind_label ?? 'Apertura de cajón',
            detail: payload.reason ? `Motivo: ${payload.reason}` : '',
        };
    }

    const partes = [payload.area, accountText(job), payload.folio ? `folio ${payload.folio}` : null].filter(Boolean);

    return {
        title: payload.kind_label ?? job?.kind_label ?? 'Trabajo de impresión',
        detail: partes.join(' · '),
    };
}

function lastEvent(job) {
    if (job.status === 'printed' && job.printed_at) return `Impreso ${fecha(job.printed_at, job)}`;
    if (job.status === 'failed' && job.failed_at) return `Falló ${fecha(job.failed_at, job)}`;
    if (job.status === 'claimed' && job.claimed_at) return `Tomado ${fecha(job.claimed_at, job)}`;

    return '';
}

function intentos(n) {
    const total = Number(n ?? 0);

    return `${total} ${total === 1 ? 'intento' : 'intentos'}`;
}

function cantidad(valor) {
    return valor === null || valor === undefined || valor === ''
        ? '—'
        : Number(valor).toLocaleString('es-MX', { maximumFractionDigits: 4 });
}

function modifierText(modifier) {
    return Number(modifier?.quantity ?? 1) > 1 ? `${modifier.name} ×${cantidad(modifier.quantity)}` : modifier?.name;
}

const failedCount = computed(() => (filters.view === 'failed' ? Number(meta.value?.total ?? 0) : failedTotal.value));

const emptyMessage = computed(() => {
    const conFiltros = filtrosActivos.value > 0 ? ' con estos filtros' : '';

    switch (filters.view) {
        case 'open':
            return `Nada por imprimir${conFiltros}: no hay trabajos pendientes, tomados ni fallidos.`;
        case 'failed':
            return `No hay trabajos fallidos${conFiltros}.`;
        case 'all':
            return `Todavía no se ha mandado nada a imprimir${conFiltros}.`;
        default:
            return `No hay trabajos en este estado${conFiltros}.`;
    }
});

// -----------------------------------------------------------------
// Reintentar / liberar
// -----------------------------------------------------------------
const mayRetry = computed(() => canWrite('printing.jobs.retry'));

/** ¿El servidor admite devolverlo a la cola? No se decide aquí: lo dice su máquina de estados en `allowed_next`. */
function canRequeue(job) {
    return Array.isArray(job?.allowed_next) && job.allowed_next.includes('pending');
}

function retryLabel(job) {
    return job.status === 'claimed' ? 'Liberar' : 'Reintentar';
}

const retryingUlid = ref(null);
const result = ref(null);

// Sin toast: el resultado se queda escrito en la pantalla (`result`), que es lo que hace falta cuando el trabajo
// desaparece de la vista de fallidos justo después de reintentarlo.
const retry = useApiForm((job) => api.post(`/print-jobs/${job.ulid}/retry`), { silent: true });

async function confirmRetry(job) {
    const { title } = describe(job);
    const donde = job.printer?.name ? ` en «${job.printer.name}»` : '';

    // Se dice ANTES qué puede pasar: volver a sacar un papel de la cocina puede hacer que se prepare la comida dos veces,
    // que es por lo que el servidor lo audita.
    const mensaje = job.status === 'claimed'
        ? `¿Liberar «${title}»${donde}?\n\nLo tomó «${job.claimed_by_agent ?? 'un agente'}» (${fecha(job.claimed_at, job)}) y no ha reportado si salió. Libéralo sólo si ese agente dejó de responder: si en realidad lo está imprimiendo, el papel saldrá dos veces.`
        : `¿Reintentar «${title}»${donde}?\n\nVuelve a la cola y un agente lo imprimirá otra vez. Si el papel sí había salido, saldrá de nuevo, y una comanda repetida puede prepararse dos veces.`;

    if (!window.confirm(mensaje)) {
        return;
    }

    result.value = null;
    retryingUlid.value = job.ulid;

    let response;

    try {
        response = await retry.submit(job);
    } finally {
        retryingUlid.value = null;
    }

    if (response?.data) {
        const updated = response.data;

        // Se pinta en su fila de inmediato; la recarga de abajo lo acomoda en la vista que toque.
        jobs.value = jobs.value.map((j) => (j.ulid === updated.ulid ? updated : j));

        result.value = {
            title,
            printer: updated.printer?.name ?? job.printer?.name ?? null,
            attempts: updated.attempts,
        };

        if (detail.value?.ulid === updated.ulid) {
            closeDetail();
        }
    } else if (detail.value?.ulid === job.ulid) {
        // No se pudo —lo más probable, que ya cambió de estado mientras se miraba—: el detalle se vuelve a consultar
        // para que la decisión siguiente se tome sobre lo que hay ahora.
        openDetail(job);
    }

    await load({ silent: true });
}

// -----------------------------------------------------------------
// Detalle
// -----------------------------------------------------------------
const detail = ref(null);
const detailLoading = ref(false);
const detailError = ref(null);

const detailInfo = computed(() => (detail.value ? describe(detail.value) : null));

/**
 * Abre con lo que ya trae la fila, al instante, y consulta el trabajo al servidor: la lista puede traer hasta un ciclo de
 * refresco encima, y aquí es donde se decide reintentar — conviene decidirlo sobre el estado de ahora.
 */
async function openDetail(job) {
    detail.value = job;
    detailError.value = null;
    detailLoading.value = true;

    try {
        const response = await api.get(`/print-jobs/${job.ulid}`);

        if (detail.value?.ulid === job.ulid && response?.data) {
            detail.value = response.data;
        }
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (detail.value?.ulid === job.ulid) {
            detailError.value = e.title;
        }
    } finally {
        if (detail.value?.ulid === job.ulid) {
            detailLoading.value = false;
        }
    }
}

function closeDetail() {
    detail.value = null;
    detailLoading.value = false;
    detailError.value = null;
}

// -----------------------------------------------------------------
// Refresco automático
// -----------------------------------------------------------------
const autoRefresh = ref(true);
let timer = null;

function isStale() {
    return updatedAt.value === null || Date.now() - updatedAt.value.getTime() >= REFRESH_MS;
}

function tick() {
    if (!autoRefresh.value || document.hidden) {
        return;
    }

    if (loading.value || refreshing.value || retry.processing.value) {
        return;
    }

    // Un error del cliente no se arregla repitiendo la petición: un 403 seguirá siendo 403. Uno del servidor o de la red
    // sí puede resolverse solo, y ése se sigue intentando.
    const status = Number(error.value?.status ?? 0);

    if (status >= 400 && status < 500) {
        return;
    }

    load({ silent: true });
}

function onVisibilityChange() {
    if (!document.hidden && isStale()) {
        tick();
    }
}

watch(autoRefresh, (encendido) => {
    if (encendido && isStale()) {
        tick();
    }
});

onMounted(() => {
    load();
    loadLookups();

    timer = window.setInterval(tick, REFRESH_MS);
    document.addEventListener('visibilitychange', onVisibilityChange);
});

// Sin esto, salir de la pantalla dejaría el temporizador vivo, pidiendo trabajos cada 15 s para una pantalla que ya no
// existe — uno más por cada visita.
onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibilityChange);
});

const columns = [
    { key: 'what', label: 'Qué se imprime' },
    { key: 'printer', label: 'Impresora' },
    { key: 'status', label: 'Estado' },
    { key: 'attempts', label: 'Intentos', width: '6rem', align: 'right' },
    { key: 'dates', label: 'Cuándo', width: '13rem' },
    { key: 'actions', label: '', width: '13rem' },
];
</script>

<template>
    <Head title="Trabajos de impresión" />

    <!--
        «Impresoras» sólo para quien la puede ver: un cajero ve los trabajos (`printing.jobs.view`) sin ver la configuración
        de impresoras, y el enlace lo llevaría a una lista que le responde 403.
    -->
    <Link v-if="can('organization.printers.view')" href="/admin/impresoras" class="volver">‹ Impresoras</Link>

    <ListHeader
        title="Trabajos de impresión"
        subtitle="Qué se mandó a imprimir —comandas, tickets y aperturas de cajón—, qué salió y qué falló. Un fallo de impresión no detiene la venta: se ve aquí, y un trabajo fallido no se reintenta solo. Si un área no tiene impresora asignada, sus comandas nunca se encolan y tampoco aparecen en esta lista."
        :count="meta.total ?? null"
        :active-count="filtrosActivos"
        @clear="limpiarFiltros"
    >
        <template #filters>
            <select v-if="printers.length" v-model="filters.printer" class="input input--select" aria-label="Impresora">
                <option value="">Todas las impresoras</option>
                <option v-for="p in printers" :key="p.ulid" :value="p.ulid">{{ printerOption(p) }}</option>
            </select>

            <select v-if="branches.length > 1" v-model="filters.branch" class="input input--select" aria-label="Sucursal">
                <option value="">Todas las sucursales</option>
                <option v-for="b in branches" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
            </select>

            <select v-model="filters.kind" class="input input--select" aria-label="Tipo de trabajo">
                <option value="">Todo tipo de trabajo</option>
                <option value="ticket">Comandas y tickets</option>
                <option value="drawer_open">Aperturas de cajón</option>
            </select>
        </template>

        <template #action>
            <button type="button" class="button button--neutral" :disabled="loading || refreshing" @click="refresh">
                <Icon name="refresh" /> {{ refreshing ? 'Actualizando…' : 'Actualizar' }}
            </button>
        </template>
    </ListHeader>

    <div class="state-bar">
        <div class="filtros" role="group" aria-label="Estado de los trabajos">
            <button
                v-for="v in VIEWS"
                :key="v.value"
                type="button"
                class="filtro"
                :class="{ 'filtro--activo': filters.view === v.value }"
                :aria-pressed="filters.view === v.value"
                @click="filters.view = v.value"
            >
                {{ v.label }}
                <span v-if="v.value === 'failed' && failedCount > 0" class="filtro__count">{{ failedCount }}</span>
            </button>
        </div>

        <p class="live">
            <label class="live__auto">
                <input v-model="autoRefresh" type="checkbox" />
                Actualizar cada 15 s
            </label>
            <span v-if="updatedAt">Al día a las {{ clock(updatedAt) }}</span>
        </p>
    </div>

    <p v-if="lookupError" class="alert" role="alert">
        No se pudieron cargar las impresoras o las sucursales: los filtros no podrán ofrecerlas y las horas saldrán en la de
        la sucursal activa. Detalle: {{ lookupError }}
    </p>

    <p v-if="refreshNotice" class="alert alert--notice" role="alert">
        No se pudo actualizar la lista<template v-if="updatedAt">; lo que ves es de las {{ clock(updatedAt) }}</template>.
        Detalle: {{ refreshNotice }}
    </p>

    <p v-if="retry.generalError.value && !detail" class="alert" role="alert">
        No se pudo reintentar: {{ retry.generalError.value }}
    </p>

    <div v-if="result" class="alert alert--ok result" role="status">
        <p class="result__text">
            «{{ result.title }}» volvió a la cola<template v-if="result.printer"> de «{{ result.printer }}»</template>
            ({{ intentos(result.attempts) }} hasta ahora). Saldrá en cuanto un agente de su sucursal lo tome<template
                v-if="can('organization.printers.view')"
            >; si no sale, revisa en <Link href="/admin/impresoras/agentes">Agentes de impresión</Link> que haya uno en
                línea</template>.
        </p>
        <button type="button" class="link-button" @click="result = null"><Icon name="x" /> Cerrar</button>
    </div>

    <DataTable
        :columns="columns"
        :rows="jobs"
        :loading="loading"
        :error="error"
        :empty-message="emptyMessage"
    >
        <template #cell:what="{ row }">
            <span class="what">{{ describe(row).title }}</span>
            <span v-if="describe(row).detail" class="muted wrap">{{ describe(row).detail }}</span>
        </template>

        <template #cell:printer="{ row }">
            <span>{{ row.printer?.name ?? '—' }}</span>
            <span v-if="manyBranches && branchOf(row)" class="muted">{{ branchOf(row).name }}</span>
            <span v-if="row.printer?.target" class="muted mono">{{ row.printer.target }}</span>
        </template>

        <template #cell:status="{ row }">
            <span class="badge" :class="STATUS_BADGES[row.status] ?? 'badge--off'">{{ row.status_label }}</span>
            <!-- El motivo del fallo es lo primero que se busca: «Sin papel» dice qué hacer antes de abrir nada. -->
            <span v-if="row.status === 'failed' && row.last_error" class="error-text">{{ row.last_error }}</span>
            <span v-else-if="row.last_error" class="muted wrap">Último error: {{ row.last_error }}</span>
            <span v-if="row.status === 'claimed' && row.claimed_by_agent" class="muted">Lo tiene «{{ row.claimed_by_agent }}»</span>
            <span v-else-if="row.status === 'failed' && row.claimed_by_agent" class="muted">Lo intentó «{{ row.claimed_by_agent }}»</span>
        </template>

        <template #cell:dates="{ row }">
            <span>Se mandó {{ fecha(row.created_at, row) }}</span>
            <span v-if="lastEvent(row)" class="muted">{{ lastEvent(row) }}</span>
        </template>

        <template #cell:actions="{ row }">
            <div class="row-actions">
                <button
                    v-if="mayRetry && canRequeue(row)"
                    type="button"
                    class="link-button"
                    :disabled="retry.processing.value"
                    @click="confirmRetry(row)"
                >
                    <Icon name="refresh" /> {{ retryingUlid === row.ulid ? 'Reintentando…' : retryLabel(row) }}
                </button>
                <button type="button" class="link-button" @click="openDetail(row)"><Icon name="eye" /> Detalle</button>
            </div>
        </template>
    </DataTable>

    <Paginacion :meta="meta" v-model:page="filters.page" item-label="trabajos" />

    <!-- Detalle -->
    <div v-if="detail" class="drawer-backdrop" @click.self="closeDetail">
        <section class="drawer" role="dialog" aria-modal="true" :aria-label="`Trabajo de impresión: ${detailInfo.title}`">
            <FormHeader :title="detailInfo.title" :subtitle="detailInfo.detail" />

            <p v-if="detailLoading" class="muted">Consultando el estado actual…</p>
            <p v-if="detailError" class="alert" role="alert">
                No se pudo consultar el estado actual; se muestra el de la lista. Detalle: {{ detailError }}
            </p>
            <p v-if="retry.generalError.value" class="alert" role="alert">
                No se pudo reintentar: {{ retry.generalError.value }}
            </p>

            <dl class="sheet">
                <dt>Estado</dt>
                <dd>
                    <span class="badge" :class="STATUS_BADGES[detail.status] ?? 'badge--off'">{{ detail.status_label }}</span>
                    · {{ intentos(detail.attempts) }}
                </dd>

                <template v-if="detail.last_error">
                    <dt>{{ detail.status === 'failed' ? 'Error' : 'Último error' }}</dt>
                    <dd :class="{ 'error-text': detail.status === 'failed' }">{{ detail.last_error }}</dd>
                </template>

                <dt>Impresora</dt>
                <dd>
                    {{ detail.printer?.name ?? '—' }}
                    <span v-if="detail.printer?.target" class="muted mono">
                        {{ detail.printer.target }}<template v-if="detail.printer.paper_width"> · {{ detail.printer.paper_width }} mm</template>
                    </span>
                </dd>

                <template v-if="branchOf(detail)">
                    <dt>Sucursal</dt>
                    <dd>{{ branchOf(detail).name }}</dd>
                </template>

                <template v-if="detail.claimed_by_agent">
                    <dt>Agente</dt>
                    <dd>{{ detail.claimed_by_agent }}</dd>
                </template>

                <dt>Se mandó</dt>
                <dd>{{ fecha(detail.created_at, detail) }}</dd>

                <template v-if="detail.claimed_at">
                    <dt>Tomado</dt>
                    <dd>{{ fecha(detail.claimed_at, detail) }}</dd>
                </template>

                <template v-if="detail.printed_at">
                    <dt>Impreso</dt>
                    <dd>{{ fecha(detail.printed_at, detail) }}</dd>
                </template>

                <template v-if="detail.failed_at">
                    <dt>Falló</dt>
                    <dd>{{ fecha(detail.failed_at, detail) }}</dd>
                </template>
            </dl>

            <p v-if="STATUS_HINTS[detail.status]" class="hint">{{ STATUS_HINTS[detail.status] }}</p>

            <template v-if="detail.kind === 'drawer_open'">
                <h3 class="section-label">Qué se pidió</h3>
                <p class="paper-note">
                    Abrir el cajón de dinero: no sale papel, se manda la señal por la impresora.<template
                        v-if="detail.payload?.reason"
                    > Motivo: «{{ detail.payload.reason }}».</template>
                </p>
            </template>

            <template v-else>
                <h3 class="section-label">Qué dice el papel</h3>

                <dl class="sheet">
                    <template v-if="detail.payload?.area">
                        <dt>Área</dt>
                        <dd>{{ detail.payload.area }}</dd>
                    </template>

                    <!-- Un papel sin ticket del POS es la comanda de un pedido de la tienda: ahí no hay cuenta, hay pedido. -->
                    <template v-if="accountText(detail)">
                        <dt>{{ detail.ticket ? 'Cuenta' : 'Pedido' }}</dt>
                        <dd>{{ accountText(detail) }}</dd>
                    </template>

                    <template v-if="detail.payload?.order_sequence">
                        <dt>Orden</dt>
                        <dd>{{ detail.payload.order_sequence }}</dd>
                    </template>

                    <template v-if="detail.payload?.folio">
                        <dt>Folio</dt>
                        <dd>{{ detail.payload.folio }}</dd>
                    </template>

                    <template v-if="detail.payload?.issued_at">
                        <dt>Emitido</dt>
                        <dd>{{ fecha(detail.payload.issued_at, detail) }}</dd>
                    </template>
                </dl>

                <ul v-if="detail.payload?.items?.length" class="lines">
                    <li v-for="(line, i) in detail.payload.items" :key="i">
                        <strong>{{ cantidad(line.quantity) }} ×</strong> {{ line.name ?? '—' }}
                        <span v-if="line.modifiers?.length" class="lines__mods">
                            {{ line.modifiers.map(modifierText).join(' · ') }}
                        </span>
                    </li>
                </ul>
                <p v-else class="muted">El papel no trae renglones.</p>

                <p v-if="detail.payload?.totals?.total" class="paper-total">
                    Total {{ formatMoney(detail.payload.totals.total) }}
                </p>
            </template>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="closeDetail"><Icon name="x" /> Cerrar</button>
                <button
                    v-if="mayRetry && canRequeue(detail)"
                    type="button"
                    class="button"
                    :disabled="retry.processing.value"
                    @click="confirmRetry(detail)"
                >
                    <Icon name="refresh" /> {{ retryingUlid === detail.ulid ? 'Reintentando…' : retryLabel(detail) }}
                </button>
            </div>
        </section>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.volver {
    display: inline-block;
    margin-bottom: 0.35rem;
    color: var(--color-suave);
    font-size: 0.85rem;
    text-decoration: none;
}

.volver:hover {
    color: var(--color-acento);
}

.muted {
    display: block;
    color: var(--color-suave);
    font-size: 0.8rem;
}

.mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.8rem;
}

.what {
    display: block;
    font-weight: 600;
}

/*
 * Las celdas de la tabla no parten líneas (`white-space: nowrap` en DataTable), y un motivo de error puede tener 300
 * caracteres: sin esto, un solo fallo ensanchaba la tabla a lo largo de toda la pantalla.
 */
.wrap,
.error-text {
    white-space: normal;
    max-width: 22rem;
    overflow-wrap: anywhere;
}

.error-text {
    display: block;
    margin-top: 0.25rem;
    color: var(--color-peligro);
    font-size: 0.8rem;
}

/* Falló: rojo. `admin-page.css` sólo trae ok/off/warn; el texto oscuro sobre el tinte se lee en los dos temas. */
.badge--fallo {
    background: var(--color-peligro-tenue);
    color: var(--color-peligro-texto);
}

/* Las pastillas de estado y, a su lado, de cuándo es lo que se ve. En pantallas angostas, una debajo de la otra. */
.state-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem 1rem;
    margin-bottom: 1rem;
}

.filtro__count {
    display: inline-grid;
    place-items: center;
    min-width: 1.2rem;
    height: 1.2rem;
    margin-left: 0.3rem;
    padding: 0 0.3rem;
    border-radius: 999px;
    background: var(--color-peligro-tenue);
    color: var(--color-peligro-texto);
    font-size: 0.72rem;
    font-weight: 700;
    line-height: 1;
}

.live {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.25rem 0.75rem;
    margin: 0;
    font-size: 0.8rem;
    color: var(--color-suave);
}

.live__auto {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    cursor: pointer;
}

.result {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.result__text {
    margin: 0;
}

.result__text a {
    color: inherit;
    font-weight: 600;
}

.result .link-button {
    flex: none;
}

.sheet {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 0.4rem 0.9rem;
    margin: 0.9rem 0 1rem;
    font-size: 0.88rem;
}

.sheet dt {
    color: var(--color-suave);
}

.sheet dd {
    margin: 0;
    min-width: 0;
    overflow-wrap: anywhere;
}

.hint {
    margin: 0 0 1.1rem;
    padding: 0.6rem 0.75rem;
    border-radius: var(--radio);
    background: var(--color-fondo);
    color: var(--color-contenido);
    font-size: 0.85rem;
    line-height: 1.45;
}

.lines {
    display: grid;
    gap: 0.35rem;
    margin: 0 0 0.9rem;
    padding: 0;
    list-style: none;
    font-size: 0.9rem;
}

.lines__mods {
    display: block;
    padding-left: 1.4rem;
    color: var(--color-suave);
    font-size: 0.8rem;
}

.paper-note {
    margin: 0 0 1rem;
    font-size: 0.88rem;
}

.paper-total {
    margin: 0;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}
</style>
