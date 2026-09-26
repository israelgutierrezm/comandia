<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { api, ApiError, getAllPages } from '../../api/client';
import { useApiForm } from '../../stores/useResourceList';
import { pushToast } from '../../stores/useToasts';
import { useAuthorization } from '../../composables/useAuthorization';
import { formatMoney } from '../../support/money';
import ArticlePicker from '../catalog/ArticlePicker.vue';
import FormHeader from '../FormHeader.vue';
import Icon from '../Icon.vue';
import LotSelect from './LotSelect.vue';
import { formatQuantity, nowForDateTimeInput, todayInZone, zonedWallTimeToIso } from './inventoryFormat';

/**
 * Panel para registrar a mano una ENTRADA, una SALIDA o un AJUSTE de inventario (D157, D158).
 *
 * ## Tres tipos, tres endpoints, tres permisos
 *
 * Son tres cosas distintas del negocio —entró algo que no fue compra; salió algo que no fue venta ni merma; hay un
 * descuadre que nadie sabe explicar— y el servidor las recibe en tres endpoints, cada uno con su permiso del catálogo
 * cerrado. El panel ofrece sólo los tipos que el rol activo puede escribir, y el tipo lo decide el endpoint al que se
 * manda, no un campo del cuerpo: desde aquí no hay forma de registrar un «consumo por venta» a mano (D158).
 *
 * ## Los campos de cada request, y ni uno más
 *
 * - Cantidad SIEMPRE positiva y en la unidad base: la dirección la pone el tipo. En el ajuste se elige —es la
 *   información— y no tiene valor por omisión.
 * - Nota obligatoria sólo en el ajuste: un descuadre sin explicación no se puede investigar después.
 * - Costo unitario sólo en la carga inicial, el único de los tres que trae su propio costo
 *   (`StockMovementKind::carriesOwnCost`); los demás se valúan al costo vigente del artículo (D152). La salida ni lo
 *   usaría: el servidor lo ignora.
 * - Lote sólo si el artículo lleva lotes. Vacío en una salida = automático, primero lo que caduca (§6.2: sin selección
 *   manual obligatoria).
 * - «Cuándo ocurrió», opcional y en la hora de la sucursal: sirve para la carga inicial con la fecha del inventario.
 *
 * ## El kardex no se corrige
 *
 * Lo registrado es un renglón más, firmado por quien lo registra. Por eso al terminar el panel enseña lo que quedó
 * escrito —cantidad, lote, saldo— y lleva al kardex del artículo: la forma de «deshacer» es otro movimiento.
 *
 * ## Sin PIN
 *
 * Ninguno de los tres endpoints pide autorización por monto (sólo las mermas y el cierre de conteos responden 409
 * `authorization_required`), así que este panel no abre el diálogo del PIN.
 */
const props = defineProps({
    /** El artículo con el que abre (el del kardex, o el de un renglón de existencias). `null` = se elige aquí. */
    article: { type: Object, default: null },

    /** El artículo no se puede cambiar: el kardex es de UN artículo, y registrar otro ahí no se vería reflejado. */
    lockArticle: { type: Boolean, default: false },

    /** El almacén con el que abre, `{ ulid, name }`, cuando quien abre el panel ya lo sabe (un renglón de existencias). */
    warehouse: { type: Object, default: null },

    /** El enlace «Ver en el kardex» del resultado. Sobra cuando el panel se abrió desde el propio kardex. */
    showKardexLink: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'recorded']);

const page = usePage();
const { can, canWrite } = useAuthorization();
const uid = useId();

/** La zona de la sucursal activa: en ella se teclea «cuándo ocurrió» y se decide qué día es hoy. */
const zona = computed(() => page.props.context?.branch_timezone ?? null);

const TIPOS = [
    {
        value: 'entry',
        label: 'Entrada',
        permission: 'inventory.entries.create',
        endpoint: '/stock-entries',
        submitLabel: 'Registrar entrada',
        hint: 'Entró algo que no fue compra: muestras del proveedor, una devolución de cliente, mercancía que apareció. '
            + 'Las compras van en Recepciones, con su factura.',
    },
    {
        value: 'exit',
        label: 'Salida',
        permission: 'inventory.exits.create',
        endpoint: '/stock-exits',
        submitLabel: 'Registrar salida',
        hint: 'Salió algo que no fue venta ni merma: consumo interno, una degustación, se lo llevó el dueño. Si se echó '
            + 'a perder o se rompió, es una merma: va en Mermas, con su motivo.',
    },
    {
        value: 'adjustment',
        label: 'Ajuste',
        permission: 'inventory.adjustments.create',
        endpoint: '/stock-adjustments',
        submitLabel: 'Registrar ajuste',
        hint: 'El sistema dice una cantidad, hay otra y no se sabe por qué. Es la confesión de un descuadre: se registra '
            + 'la diferencia y se explica por escrito. Para cuadrar un almacén completo contra lo que hay, usa un conteo físico.',
    },
];

const tiposPermitidos = computed(() => TIPOS.filter((t) => canWrite(t.permission)));

/**
 * Un formulario en blanco. `conservar` guarda lo que tiene sentido repetir al registrar otro —tipo, almacén, la bandera
 * de carga inicial y su fecha—: una carga inicial son decenas de entradas seguidas en el mismo almacén.
 */
function formularioNuevo(conservar = {}) {
    const valor = (clave, omision) => (Object.hasOwn(conservar, clave) ? conservar[clave] : omision);

    return {
        kind: valor('kind', tiposPermitidos.value[0]?.value ?? ''),
        warehouse_ulid: valor('warehouse_ulid', props.warehouse?.ulid ?? ''),
        article: valor('article', props.article),
        lot_ulid: '',
        quantity: '',
        direction: '',
        is_initial_load: valor('is_initial_load', false),
        unit_cost: '',
        notes: '',
        occurred_at: valor('occurred_at', ''),
    };
}

const form = ref(formularioNuevo());
const tipo = computed(() => TIPOS.find((t) => t.value === form.value.kind) ?? null);

/** Lo que quedó registrado: `{ kind, article, movements }`. Mientras sea `null`, se ve el formulario. */
const resultado = ref(null);

// Todo el estado se declara ANTES de cualquier `watch`: el del artículo corre inmediato y toca también el del lote nuevo,
// y una constante leída antes de su declaración revienta el componente al montarse.

const almacenes = ref([]);
const almacenesCargados = ref(false);
const almacenesProhibidos = ref(false);
const almacenesError = ref(null);

const existencias = ref([]);
const existenciasVisibles = ref(false);
const lotes = ref([]);
const articuloError = ref(null);

const nuevoLote = ref(null);
const creandoLote = ref(false);
const loteErrores = ref({});
const loteError = ref(null);

// -----------------------------------------------------------------------------------------------------------------
// Almacenes
// -----------------------------------------------------------------------------------------------------------------

onMounted(cargarAlmacenes);

async function cargarAlmacenes() {
    try {
        // Todas las páginas: el servidor corta en 100, y el almacén 101 simplemente no aparecería.
        almacenes.value = (await getAllPages('/warehouses', { status: 'active' }))
            // El de tránsito no se opera a mano: lo escriben sólo las transferencias (D190), y ofrecerlo daría un 422.
            .filter((w) => w.kind !== 'transit');
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (e.status === 403) {
            almacenesProhibidos.value = true;
        } else {
            almacenesError.value = e.message;
        }
    } finally {
        almacenesCargados.value = true;
    }
}

/**
 * Los almacenes que se pueden elegir.
 *
 * Si el rol no puede ver la lista (403 en `/warehouses`; la plantilla del Almacenista no trae «Ver almacenes»), no se
 * inventa una: se ofrecen los que ya se conocen por otra vía legítima —el del renglón desde el que se abrió el panel y
 * los almacenes donde el artículo tiene existencia—, y se dice por qué la lista es corta. El servidor sigue decidiendo si
 * esa persona puede operar en cada uno.
 */
const opcionesAlmacen = computed(() => {
    if (!almacenesProhibidos.value) {
        return almacenes.value;
    }

    const vistos = new Map();

    for (const almacen of [props.warehouse, ...existencias.value.map((fila) => fila.warehouse)]) {
        if (almacen?.ulid && !vistos.has(almacen.ulid)) {
            vistos.set(almacen.ulid, almacen);
        }
    }

    return [...vistos.values()];
});

function almacenPorOmision(opciones) {
    const sucursal = page.props.context?.branch_ulid;

    return (opciones.find((w) => w.ulid === props.warehouse?.ulid)
        ?? opciones.find((w) => sucursal && w.branch?.ulid === sucursal)
        ?? opciones[0])?.ulid ?? '';
}

// El almacén elegido tiene que estar entre las opciones: el del renglón puede ser el de tránsito, o uno dado de baja, y
// un `<select>` con un valor que no está en la lista se ve vacío pero se enviaría igual.
watch(opcionesAlmacen, (opciones) => {
    if (!opciones.some((w) => w.ulid === form.value.warehouse_ulid)) {
        form.value.warehouse_ulid = almacenPorOmision(opciones);
    }
});

function nombreAlmacen(almacen) {
    if (almacen.branch?.name) {
        return `${almacen.name} · ${almacen.branch.name}`;
    }

    return almacen.kind === 'central' ? `${almacen.name} · central` : almacen.name;
}

// -----------------------------------------------------------------------------------------------------------------
// Artículo: su existencia y sus lotes
// -----------------------------------------------------------------------------------------------------------------

/** Una lectura de apoyo: `null` si el rol no la puede ver (403), en lugar de tumbar el panel entero. */
async function leerSiSePuede(peticion) {
    try {
        return await peticion;
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) {
            return null;
        }

        throw e;
    }
}

async function cargarArticulo(ulid) {
    existencias.value = [];
    existenciasVisibles.value = false;
    lotes.value = [];
    articuloError.value = null;
    form.value.lot_ulid = '';
    nuevoLote.value = null;

    if (!ulid) {
        return;
    }

    try {
        const [saldos, activos, ficha] = await Promise.all([
            leerSiSePuede(api.get(`/articles/${ulid}/stock`)),
            leerSiSePuede(api.get(`/articles/${ulid}/lots`)),

            // Un renglón de existencias trae el nombre y la unidad del artículo, no si lleva lotes: se completa con su
            // ficha. El buscador y el kardex ya la traen completa.
            typeof form.value.article?.tracks_lots === 'boolean'
                ? Promise.resolve(null)
                : leerSiSePuede(api.get(`/articles/${ulid}`)),
        ]);

        // Si mientras tanto se eligió otro artículo, esta respuesta ya no es la que se está mirando.
        if (form.value.article?.ulid !== ulid) {
            return;
        }

        existencias.value = saldos?.data ?? [];
        existenciasVisibles.value = saldos !== null;
        lotes.value = activos?.data ?? [];

        if (ficha?.data) {
            form.value.article = { ...form.value.article, ...ficha.data };
        }
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (form.value.article?.ulid === ulid) {
            articuloError.value = e.message;
        }
    }
}

const unidad = computed(() => form.value.article?.base_unit?.code ?? form.value.article?.base_unit_code ?? '');

/**
 * ¿El artículo lleva lotes? Lo dice su ficha. Si el rol no puede leer el catálogo, se deduce de que tenga lotes o saldos
 * por lote: es la única pista disponible, y equivocarse sólo esconde un selector opcional.
 */
const llevaLotes = computed(() => {
    const articulo = form.value.article;

    if (!articulo) {
        return false;
    }

    if (typeof articulo.tracks_lots === 'boolean') {
        return articulo.tracks_lots;
    }

    return lotes.value.length > 0 || existencias.value.some((fila) => fila.lot);
});

/** Los saldos del artículo en el almacén elegido: uno por lote, más el de «sin lote». Tal como los manda el servidor. */
const saldosAqui = computed(() => existencias.value.filter((fila) => fila.warehouse?.ulid === form.value.warehouse_ulid));

const loteVacio = computed(() => (form.value.kind === 'exit' ? 'Automático: primero lo que caduca' : 'Sin lote'));

const pistaLote = computed(() => {
    if (form.value.kind === 'exit') {
        return 'Vacío = automático: sale primero lo que caduca antes, y si los lotes no alcanzan, de lo que no tiene lote. '
            + 'Elige uno sólo si estás viendo la caja de la que sale.';
    }

    if (form.value.kind === 'entry') {
        return 'El lote impreso en la caja. Sin lote, entra a la existencia sin lote: la salida automática la toma sólo '
            + 'cuando se acaban los lotes.';
    }

    return 'El saldo que se corrige: el de un lote, o el de la existencia sin lote.';
});

// El artículo manda qué existencia y qué lotes se cargan. Inmediato: el del kardex o el del renglón ya viene elegido.
watch(() => form.value.article?.ulid ?? null, (ulid) => cargarArticulo(ulid), { immediate: true });

// Un lote fuera de surtido sirve para sacar o ajustar, no para meter: al pasar a entrada se vuelve a elegir.
watch(() => form.value.kind, (kind) => {
    if (kind === 'entry' && form.value.lot_ulid && !lotes.value.some((l) => l.ulid === form.value.lot_ulid)) {
        form.value.lot_ulid = '';
    }
});

// -----------------------------------------------------------------------------------------------------------------
// Lote nuevo, sin salir del panel
// -----------------------------------------------------------------------------------------------------------------

/*
 * Una entrada de un artículo con lotes casi siempre trae un lote que todavía no existe: el de la caja que acaba de
 * llegar. Mandar a otra pantalla a crearlo perdería lo capturado, así que se crea aquí —con el mismo endpoint y el mismo
 * permiso que la pantalla de lotes— y queda elegido.
 *
 * Son campos sueltos y no un `<form>`: HTML no admite formularios anidados. Por eso Enter en ellos crea el lote en
 * lugar de enviar el movimiento.
 */

const hoy = computed(() => todayInZone(zona.value));

const puedeCrearLote = computed(() => canWrite('inventory.lots.manage')
    && (form.value.kind === 'entry' || (form.value.kind === 'adjustment' && form.value.direction === 'in')));

function abrirNuevoLote() {
    nuevoLote.value = { code: '', received_at: hoy.value, expires_at: '' };
    loteErrores.value = {};
    loteError.value = null;
}

async function crearLote() {
    const articulo = form.value.article;

    if (creandoLote.value || !nuevoLote.value || !articulo) {
        return;
    }

    creandoLote.value = true;
    loteErrores.value = {};
    loteError.value = null;

    try {
        const creado = (await api.post(`/articles/${articulo.ulid}/lots`, {
            code: nuevoLote.value.code.trim(),
            expires_at: nuevoLote.value.expires_at || null,
            received_at: nuevoLote.value.received_at,
        })).data;

        pushToast(`Lote ${creado.code} creado.`);

        // Se vuelve a pedir la lista para que el lote nuevo tome su lugar en el orden de salida. Si esa lectura falla,
        // el lote ya existe: se agrega al final para poder elegirlo, y el orden se corrige en la próxima carga.
        let vigentes;

        try {
            vigentes = (await api.get(`/articles/${articulo.ulid}/lots`)).data ?? [];
        } catch (e) {
            if (!(e instanceof ApiError)) {
                throw e;
            }

            vigentes = [...lotes.value, creado];
        }

        // Si mientras tanto se cambió de artículo, el lote quedó creado pero ya no es el de este formulario.
        if (form.value.article?.ulid !== articulo.ulid) {
            return;
        }

        lotes.value = vigentes;
        form.value.lot_ulid = creado.ulid;
        nuevoLote.value = null;
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        if (e.isValidation) {
            loteErrores.value = e.fieldErrors;
        } else {
            loteError.value = e.message;
        }
    } finally {
        creandoLote.value = false;
    }
}

// -----------------------------------------------------------------------------------------------------------------
// Registro
// -----------------------------------------------------------------------------------------------------------------

/** El tope de «cuándo ocurrió»: el servidor no admite el futuro. Se recalcula al enfocar el campo. */
const ahoraMaximo = ref(nowForDateTimeInput(zona.value));

const pistaCantidad = computed(() => (form.value.kind === 'adjustment'
    ? 'La DIFERENCIA, no lo que contaste: si el sistema dice 10 y hay 8, es un ajuste que resta 2. Hasta cuatro decimales.'
    : 'Siempre positiva y en la unidad base del artículo: si entra o sale lo dice el tipo. Hasta cuatro decimales.'));

/**
 * El éxito no va a un toast: se enseña en el propio panel, con lo que quedó escrito y el enlace al kardex, que un toast
 * no puede llevar (`silent`).
 */
const save = useApiForm(async () => {
    const f = form.value;

    const cuerpo = {
        warehouse_ulid: f.warehouse_ulid,
        article_ulid: f.article.ulid,
        quantity: String(f.quantity).trim(),
        lot_ulid: f.lot_ulid || null,
        notes: f.notes.trim() || null,
    };

    if (f.occurred_at) {
        cuerpo.occurred_at = zonedWallTimeToIso(f.occurred_at, zona.value);
    }

    if (f.kind === 'entry') {
        cuerpo.is_initial_load = f.is_initial_load;

        if (f.is_initial_load && String(f.unit_cost).trim() !== '') {
            cuerpo.unit_cost = String(f.unit_cost).trim();
        }
    }

    // Sólo el ajuste la manda: en entradas y salidas el servidor la prohíbe, porque la decide el tipo.
    if (f.kind === 'adjustment') {
        cuerpo.direction = f.direction;
    }

    const respuesta = await api.post(tipo.value.endpoint, cuerpo);

    return {
        kind: f.kind,
        article: f.article,

        // Entradas y ajustes devuelven UN movimiento; la salida, una lista: FEFO puede partirla en varios lotes (D164).
        movements: Array.isArray(respuesta.data) ? respuesta.data : [respuesta.data],
    };
}, { silent: true });

async function enviar() {
    if (!tipo.value || !form.value.article || save.processing.value) {
        return;
    }

    const registrado = await save.submit();

    if (registrado && registrado !== true) {
        resultado.value = registrado;
        emit('recorded', registrado);
    }
}

function registrarOtro() {
    const anterior = form.value;

    resultado.value = null;
    form.value = formularioNuevo({
        kind: anterior.kind,
        warehouse_ulid: anterior.warehouse_ulid,
        article: props.lockArticle ? anterior.article : null,
        is_initial_load: anterior.is_initial_load,
        occurred_at: anterior.occurred_at,
    });

    // El mismo artículo: sus saldos cambiaron con lo que se acaba de registrar, y el `watch` no lo nota (mismo ULID).
    if (props.lockArticle) {
        cargarArticulo(form.value.article?.ulid ?? null);
    }
}

const subtituloResultado = computed(() => {
    const primero = resultado.value?.movements[0];

    return primero ? [primero.kind_label, primero.warehouse?.name].filter(Boolean).join(' · ') : '';
});

const quedoNegativo = computed(() => (resultado.value?.movements ?? []).some((m) => Number(m.balance_after) < 0));

function unidadDe(movimiento) {
    return movimiento.article?.base_unit_code ?? unidad.value;
}
</script>

<template>
    <div class="drawer-backdrop" @click.self="emit('close')">
        <!-- Lo que quedó escrito en el kardex -->
        <section v-if="resultado" class="drawer">
            <FormHeader title="Movimiento registrado" :subtitle="subtituloResultado" />

            <p class="drawer__hint" role="status">
                Quedó en el kardex de <strong>{{ resultado.article?.name }}</strong>. No se edita: si algo salió mal, se
                corrige con otro movimiento.
            </p>

            <ul class="resultado">
                <li v-for="m in resultado.movements" :key="m.ulid">
                    <strong :class="m.direction === 'in' ? 'value--in' : 'value--out'">
                        {{ m.direction === 'in' ? '+' : '−' }}{{ formatQuantity(m.quantity) }} {{ unidadDe(m) }}
                    </strong>
                    <span v-if="m.lot"> · lote {{ m.lot.code }}</span>
                    <span class="muted">
                        · queda
                        <span :class="{ 'value--negative': Number(m.balance_after) < 0 }">
                            {{ formatQuantity(m.balance_after) }} {{ unidadDe(m) }}
                        </span>
                        <template v-if="m.lot"> en ese lote</template>
                        <template v-else-if="llevaLotes"> sin lote</template>
                    </span>
                    <span v-if="m.total_cost !== null" class="muted"> · {{ formatMoney(m.total_cost) }}</span>
                    <span v-else class="muted" title="El artículo no tiene costo capturado: queda sin costo, no en cero."> · sin costo</span>
                </li>
            </ul>

            <p v-if="resultado.movements.length > 1" class="drawer__hint">
                La salida se partió por lote: primero salió lo que caduca antes, y cada renglón del kardex dice de qué
                partida salió.
            </p>

            <p v-if="quedoNegativo" class="alert alert--notice">
                El saldo quedó en negativo. No es un error —el inventario del sistema es teórico y nunca bloquea una
                operación—, pero es lo primero que el próximo conteo debe revisar.
            </p>

            <div class="drawer__actions">
                <Link
                    v-if="props.showKardexLink && can('inventory.kardex.view')"
                    :href="`/admin/existencias/${resultado.article.ulid}/kardex`"
                    class="link-button"
                ><Icon name="eye" /> Ver en el kardex</Link>
                <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cerrar</button>
                <button type="button" class="button" @click="registrarOtro"><Icon name="plus" /> Registrar otro</button>
            </div>
        </section>

        <form v-else class="drawer" @submit.prevent="enviar">
            <FormHeader title="Registrar movimiento" subtitle="Entradas, salidas y ajustes que no vienen de un documento" />

            <p class="drawer__hint">
                El kardex no se corrige: se le agrega. Lo que registres queda como un renglón más, con tu nombre, y la
                forma de deshacerlo es otro movimiento.
            </p>

            <p v-if="tiposPermitidos.length === 0" class="alert alert--notice">
                Tu rol no puede registrar entradas, salidas ni ajustes de inventario.
            </p>

            <template v-else>
                <div class="field">
                    <span :id="`${uid}-tipo`" class="field__label">Tipo de movimiento</span>
                    <div class="filtros" role="group" :aria-labelledby="`${uid}-tipo`">
                        <button
                            v-for="t in tiposPermitidos"
                            :key="t.value"
                            type="button"
                            class="filtro"
                            :class="{ 'filtro--activo': form.kind === t.value }"
                            :aria-pressed="form.kind === t.value"
                            @click="form.kind = t.value"
                        >
                            {{ t.label }}
                        </button>
                    </div>
                    <span class="field__hint">{{ tipo?.hint }}</span>
                </div>

                <p v-if="save.generalError.value" class="alert" role="alert">{{ save.generalError.value }}</p>

                <p v-if="almacenesError" class="alert" role="alert">
                    No se pudo cargar la lista de almacenes: {{ almacenesError }}
                </p>

                <p v-if="almacenesProhibidos" class="alert alert--notice">
                    Tu rol no puede ver la lista de almacenes (permiso «Ver almacenes»), así que sólo puedes elegir entre
                    los que ya se conocen: el del renglón desde el que abriste esto y los almacenes donde el artículo tiene
                    existencia. Para registrar en cualquier otro, pide que agreguen ese permiso a tu rol.
                </p>

                <div class="field">
                    <label class="field__label" :for="`${uid}-almacen`">Almacén</label>
                    <select
                        :id="`${uid}-almacen`"
                        v-model="form.warehouse_ulid"
                        class="input"
                        required
                        :disabled="opcionesAlmacen.length === 0"
                    >
                        <option v-if="opcionesAlmacen.length === 0" value="" disabled>
                            {{ almacenesCargados ? 'No hay almacenes que puedas elegir' : 'Cargando almacenes…' }}
                        </option>
                        <option v-for="w in opcionesAlmacen" :key="w.ulid" :value="w.ulid">{{ nombreAlmacen(w) }}</option>
                    </select>
                    <span v-if="save.fieldErrors.value.warehouse_ulid" class="field__error">
                        {{ save.fieldErrors.value.warehouse_ulid }}
                    </span>
                </div>

                <div class="field">
                    <label v-if="!form.article" class="field__label" :for="`${uid}-articulo`">Artículo</label>
                    <span v-else class="field__label">Artículo</span>

                    <div v-if="form.article" class="elegido">
                        <strong>{{ form.article.name }}</strong>
                        <span v-if="unidad" class="muted">se mide en {{ unidad }}</span>
                        <button v-if="!props.lockArticle" type="button" class="link-button" @click="form.article = null">
                            Cambiar
                        </button>
                    </div>

                    <!-- Enter en el buscador no envía el movimiento: todavía no hay artículo que registrar. -->
                    <div v-else @keydown.enter.prevent>
                        <ArticlePicker
                            :input-id="`${uid}-articulo`"
                            capability="inventoriable"
                            :supply-hint="false"
                            placeholder="Buscar artículo inventariable…"
                            @picked="(elegido) => (form.article = elegido)"
                        />
                        <span class="field__hint">Sólo los inventariables: lo demás no tiene existencia que mover.</span>
                    </div>

                    <span v-if="save.fieldErrors.value.article_ulid" class="field__error">
                        {{ save.fieldErrors.value.article_ulid }}
                    </span>

                    <p v-if="articuloError" class="alert" role="alert">
                        No se pudieron cargar la existencia y los lotes del artículo: {{ articuloError }}
                    </p>

                    <!-- Lo que el sistema cree que hay aquí. En un ajuste es indispensable: el ajuste es la diferencia. -->
                    <p v-else-if="form.article && existenciasVisibles && form.warehouse_ulid" class="field__hint saldo">
                        <template v-if="saldosAqui.length === 0">Sin existencia registrada en este almacén.</template>
                        <template v-else>
                            {{ form.kind === 'adjustment' ? 'El sistema dice que aquí hay' : 'Existencia en este almacén:' }}
                            <span v-for="(fila, i) in saldosAqui" :key="fila.lot?.ulid ?? 'sin-lote'">
                                <template v-if="i > 0"> · </template>
                                <strong :class="{ 'value--negative': fila.is_negative }">
                                    {{ formatQuantity(fila.quantity) }} {{ unidad }}
                                </strong>
                                <template v-if="llevaLotes">{{ fila.lot ? ` (lote ${fila.lot.code})` : ' (sin lote)' }}</template>
                            </span>
                        </template>
                    </p>
                </div>

                <fieldset v-if="form.kind === 'adjustment'" class="field">
                    <legend class="field__label">¿Qué encontraste?</legend>
                    <label class="opcion">
                        <input v-model="form.direction" type="radio" value="in" :name="`${uid}-direccion`" required />
                        <span>Hay <strong>más</strong> de lo que dice el sistema: el ajuste suma</span>
                    </label>
                    <label class="opcion">
                        <input v-model="form.direction" type="radio" value="out" :name="`${uid}-direccion`" required />
                        <span>Hay <strong>menos</strong> de lo que dice el sistema: el ajuste resta</span>
                    </label>
                    <span class="field__hint">Sin valor por omisión a propósito: en un ajuste, el signo es la información.</span>
                    <span v-if="save.fieldErrors.value.direction" class="field__error">
                        {{ save.fieldErrors.value.direction }}
                    </span>
                </fieldset>

                <div v-if="form.article && llevaLotes" class="field">
                    <label class="field__label" :for="`${uid}-lote`">Lote</label>
                    <LotSelect
                        :id="`${uid}-lote`"
                        v-model="form.lot_ulid"
                        :lots="lotes"
                        :stocks="existencias"
                        :warehouse-ulid="form.warehouse_ulid"
                        :include-inactive="form.kind !== 'entry'"
                        :empty-label="loteVacio"
                        :unit="unidad"
                    />
                    <span class="field__hint">{{ pistaLote }}</span>
                    <span v-if="save.fieldErrors.value.lot_ulid" class="field__error">{{ save.fieldErrors.value.lot_ulid }}</span>

                    <button
                        v-if="puedeCrearLote && !nuevoLote"
                        type="button"
                        class="link-button nuevo-lote__abrir"
                        @click="abrirNuevoLote"
                    ><Icon name="plus" /> Nuevo lote</button>

                    <!-- Con la misma condición que el botón: al pasar a salida, o a un ajuste que resta, deja de tener sentido. -->
                    <fieldset v-if="nuevoLote && puedeCrearLote" class="nuevo-lote">
                        <legend>Nuevo lote de {{ form.article.name }}</legend>

                        <p v-if="loteError" class="alert" role="alert">{{ loteError }}</p>

                        <div class="field">
                            <label class="field__label" :for="`${uid}-lote-codigo`">Código</label>
                            <input
                                :id="`${uid}-lote-codigo`"
                                v-model="nuevoLote.code"
                                class="input"
                                maxlength="40"
                                autocomplete="off"
                                @keydown.enter.prevent="crearLote"
                            />
                            <span class="field__hint">Tal como viene impreso en la caja. No se cambia después: los movimientos lo citan.</span>
                            <span v-if="loteErrores.code" class="field__error">{{ loteErrores.code }}</span>
                        </div>

                        <div class="fechas">
                            <div class="field">
                                <label class="field__label" :for="`${uid}-lote-recibido`">Recibido</label>
                                <input
                                    :id="`${uid}-lote-recibido`"
                                    v-model="nuevoLote.received_at"
                                    type="date"
                                    class="input"
                                    :max="hoy"
                                    @keydown.enter.prevent="crearLote"
                                />
                                <span v-if="loteErrores.received_at" class="field__error">{{ loteErrores.received_at }}</span>
                            </div>

                            <div class="field">
                                <label class="field__label" :for="`${uid}-lote-caducidad`">
                                    Caducidad <span class="muted">vacía si no caduca</span>
                                </label>
                                <input
                                    :id="`${uid}-lote-caducidad`"
                                    v-model="nuevoLote.expires_at"
                                    type="date"
                                    class="input"
                                    :min="nuevoLote.received_at || undefined"
                                    @keydown.enter.prevent="crearLote"
                                />
                                <span v-if="loteErrores.expires_at" class="field__error">{{ loteErrores.expires_at }}</span>
                            </div>
                        </div>

                        <div class="nuevo-lote__acciones">
                            <button type="button" class="link-button" @click="nuevoLote = null"><Icon name="x" /> Cancelar</button>
                            <button type="button" class="button" :disabled="creandoLote" @click="crearLote">
                                <Icon name="plus" /> {{ creandoLote ? 'Creando…' : 'Crear lote' }}
                            </button>
                        </div>
                    </fieldset>
                </div>

                <div class="field">
                    <label class="field__label" :for="`${uid}-cantidad`">
                        Cantidad <span v-if="unidad" class="muted">en {{ unidad }}</span>
                    </label>
                    <input
                        :id="`${uid}-cantidad`"
                        v-model="form.quantity"
                        class="input"
                        inputmode="decimal"
                        autocomplete="off"
                        required
                    />
                    <span class="field__hint">{{ pistaCantidad }}</span>
                    <span v-if="save.fieldErrors.value.quantity" class="field__error">{{ save.fieldErrors.value.quantity }}</span>
                </div>

                <div v-if="form.kind === 'entry'" class="field">
                    <label class="opcion">
                        <input v-model="form.is_initial_load" type="checkbox" />
                        <span>Es la <strong>carga inicial</strong>: lo que ya había al empezar a usar el sistema</span>
                    </label>
                    <span class="field__hint">
                        Se reporta aparte: no es una entrada del periodo, y sumarla al mes inflaría el primer mes de operación.
                    </span>
                </div>

                <div v-if="form.kind === 'entry' && form.is_initial_load" class="field">
                    <label class="field__label" :for="`${uid}-costo`">
                        Costo unitario <span class="muted">por {{ unidad || 'unidad' }}, opcional</span>
                    </label>
                    <input
                        :id="`${uid}-costo`"
                        v-model="form.unit_cost"
                        class="input"
                        inputmode="decimal"
                        autocomplete="off"
                    />
                    <span class="field__hint">
                        Vacío = se valúa al costo vigente del artículo. Captúralo si el costo de cuando se contó no es el de hoy.
                    </span>
                    <span v-if="save.fieldErrors.value.unit_cost" class="field__error">{{ save.fieldErrors.value.unit_cost }}</span>
                </div>
                <p v-else class="field__hint valuacion">
                    Se valúa al costo vigente del artículo; si no tiene costo capturado, queda «sin costo», no en cero.
                </p>

                <div class="field">
                    <label class="field__label" :for="`${uid}-notas`">
                        {{ form.kind === 'adjustment' ? 'Por qué se ajusta' : 'Notas' }}
                        <span v-if="form.kind !== 'adjustment'" class="muted">opcional</span>
                    </label>
                    <textarea
                        :id="`${uid}-notas`"
                        v-model="form.notes"
                        class="input"
                        rows="2"
                        maxlength="200"
                        :required="form.kind === 'adjustment'"
                    ></textarea>
                    <span v-if="form.kind === 'adjustment'" class="field__hint">
                        Obligatorio: meses después, un descuadre sin nota no se puede atribuir a un robo, a un error de
                        captura o a una merma que no se registró.
                    </span>
                    <span v-if="save.fieldErrors.value.notes" class="field__error">{{ save.fieldErrors.value.notes }}</span>
                </div>

                <div class="field">
                    <label class="field__label" :for="`${uid}-cuando`">
                        Cuándo ocurrió <span class="muted">opcional, hora de la sucursal</span>
                    </label>
                    <input
                        :id="`${uid}-cuando`"
                        v-model="form.occurred_at"
                        type="datetime-local"
                        class="input"
                        :max="ahoraMaximo"
                        @focus="ahoraMaximo = nowForDateTimeInput(zona)"
                    />
                    <span class="field__hint">
                        Vacío = ahora. Úsalo si ocurrió antes de capturarlo: en una carga inicial, la fecha del inventario
                        físico. El saldo del renglón es el que queda al registrarlo.
                    </span>
                    <span v-if="save.fieldErrors.value.occurred_at" class="field__error">
                        {{ save.fieldErrors.value.occurred_at }}
                    </span>
                </div>

                <div class="drawer__actions">
                    <button type="button" class="link-button" @click="emit('close')"><Icon name="x" /> Cancelar</button>
                    <button
                        type="submit"
                        class="button"
                        :disabled="save.processing.value || !form.article || !form.warehouse_ulid"
                    >
                        <Icon name="check" /> {{ save.processing.value ? 'Registrando…' : tipo?.submitLabel }}
                    </button>
                </div>
            </template>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.drawer__hint {
    margin: 0 0 0.9rem;
    color: var(--color-suave);
    font-size: 0.85rem;
}

/* Tres acciones no caben en una línea a 375 px: se acomodan en dos en lugar de salirse del panel. */
.drawer__actions {
    flex-wrap: wrap;
}

.muted {
    color: var(--color-suave);
    font-size: 0.85rem;
    font-weight: 400;
}

fieldset {
    min-width: 0;
    margin: 0 0 1rem;
    padding: 0;
    border: 0;
}

legend {
    padding: 0;
}

.elegido {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.saldo {
    margin-bottom: 0;
}

.valuacion {
    margin: -0.4rem 0 1rem;
}

.opcion {
    display: flex;
    align-items: flex-start;
    gap: 0.45rem;
    margin-bottom: 0.35rem;
    font-size: 0.9rem;
}

.opcion input {
    margin-top: 0.2rem;
}

.nuevo-lote__abrir {
    margin-top: 0.5rem;
}

.nuevo-lote {
    margin-top: 0.6rem;
    padding: 0.75rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: color-mix(in srgb, var(--color-acento) 4%, var(--color-superficie));
}

.nuevo-lote legend {
    padding: 0 0.3rem;
    font-size: 0.85rem;
    font-weight: 600;
}

.fechas {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6rem;
}

@media (max-width: 30rem) {
    .fechas {
        grid-template-columns: 1fr;
    }
}

.nuevo-lote__acciones {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.6rem;
}

.resultado {
    margin: 0 0 1rem;
    padding: 0;
    list-style: none;
}

.resultado li {
    padding: 0.45rem 0;
    border-bottom: 1px solid var(--color-borde);
    font-size: 0.9rem;
}

.value--in {
    color: var(--color-exito);
}

.value--out {
    color: var(--color-aviso);
}

.value--negative {
    color: var(--color-peligro);
    font-weight: 600;
}
</style>
