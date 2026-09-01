<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';
import { useReorder } from '../../../composables/useReorder';
import FloorCanvas from '../../../components/floor/FloorCanvas.vue';

/**
 * El editor del salón (ADR-003, §6.4).
 *
 * ## Se edita en memoria y se guarda ENTERO
 *
 * Arrastrar y redimensionar no escriben. Se acomoda el salón y se guarda una vez, porque doce mesas movidas son un acto:
 * guardarlas de una en una dejaría el plano a medias si la quinta falla, y un salón a medias describe una distribución
 * que no existió nunca — las mesas se sitúan unas respecto de otras.
 *
 * ## Añadir una mesa SÍ escribe, y por eso guarda antes lo pendiente
 *
 * Dar de alta una mesa es crear una fila (`POST`) y colocarla (`PATCH`), no mover geometría en memoria: el guardado en
 * bloque sólo actualiza mesas que ya existen. Como esa alta recarga el plano desde el servidor, primero persiste lo que
 * se venía moviendo — si no, el alta borraría el acomodo sin guardar.
 *
 * ## El conflicto se enseña, no se resuelve solo
 *
 * Si alguien más guardó mientras tanto, el servidor responde 409 **con el plano actual**. La pantalla lo pinta y deja
 * elegir: descartar lo propio o volver a aplicarlo encima. Resolverlo automáticamente sería inventarse cuál de los dos
 * salones es el bueno, y ninguno de los dos gerentes sabría qué pasó con su trabajo.
 */
const props = defineProps({
    planUlid: { type: String, default: null },
});

const page = usePage();

const plan = ref(null);
const tables = ref([]);
const elements = ref([]);
const plans = ref([]);
const loading = ref(true);
const loadError = ref(null);
const selected = ref(null);
const selectedEl = ref(null);
const dirty = ref(false);
const conflicto = ref(null);

// Deshacer/rehacer: pilas de instantáneas de la geometría/zona/canvas. Crear, duplicar o retirar pasan por el servidor
// y recargan, así que reinician el historial (no se deshacen desde aquí: son escrituras, no acomodo en memoria).
const undoStack = ref([]);
const redoStack = ref([]);
const puedeDeshacer = computed(() => undoStack.value.length > 0);
const puedeRehacer = computed(() => redoStack.value.length > 0);

// Navegación por zonas: la barra superior filtra el lienzo a una zona (o «Todas»), para editar cada zona sin mezclarlo
// todo. La gestión de zonas y el tamaño del salón viven en su propio panel, no en el lateral de la mesa.
const zonaActiva = ref(null);
const gestionAbierta = ref(false);
const zonaError = ref(null);
const mesasVisibles = computed(() =>
    zonaActiva.value ? tables.value.filter((m) => m.zone?.ulid === zonaActiva.value) : tables.value,
);

/** Espejo de los bordes de la paleta de FloorCanvas para el punto de color de cada zona en la barra y el panel. */
const COLORES_ZONA = ['#d9a441', '#cf7f88', '#74b596', '#86b0ea', '#a98ed6', '#77cfe0'];
function colorZona(i) { return COLORES_ZONA[i % COLORES_ZONA.length]; }

/**
 * Los tamaños de mesa más comunes, en centímetros (ADR-003). Poner una mesa deja de ser «escribe 140 en ancho y 80 en
 * alto» y pasa a «pon una rectangular»: el mesero piensa en mesas, no en medidas. Los asientos son la sugerencia del
 * preset; se ajustan después si hace falta.
 */
const PRESETS = [
    { key: 'chica', label: 'Chica', width: 60, height: 60, shape: 'rectangle', seats: 2 },
    { key: 'estandar', label: 'Estándar', width: 80, height: 80, shape: 'rectangle', seats: 4 },
    { key: 'grande', label: 'Grande', width: 100, height: 100, shape: 'rectangle', seats: 6 },
    { key: 'rectangular', label: 'Rectangular', width: 140, height: 80, shape: 'rectangle', seats: 6 },
    { key: 'banquete', label: 'Banquete', width: 200, height: 90, shape: 'rectangle', seats: 8 },
    { key: 'redonda', label: 'Redonda', width: 90, height: 90, shape: 'circle', seats: 4 },
];

/** El contexto de Inertia trae las llaves PLANAS, no la forma anidada del recurso de la API. */
const activeBranchUlid = computed(() => page.props.context?.branch_ulid ?? null);

onMounted(load);

/** Atajos: Ctrl/Cmd+Z deshace, Ctrl/Cmd+Y o Ctrl/Cmd+Shift+Z rehace. No mientras se escribe en un campo. */
function alTecla(evento) {
    if (! (evento.ctrlKey || evento.metaKey)) {
        return;
    }

    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(evento.target?.tagName)) {
        return;
    }

    const tecla = evento.key.toLowerCase();

    if (tecla === 'z' && ! evento.shiftKey) {
        evento.preventDefault();
        deshacer();
    } else if (tecla === 'y' || (tecla === 'z' && evento.shiftKey)) {
        evento.preventDefault();
        rehacer();
    }
}

onMounted(() => window.addEventListener('keydown', alTecla));
onUnmounted(() => window.removeEventListener('keydown', alTecla));

async function load(ulid = null) {
    loading.value = true;
    loadError.value = null;

    try {
        const lista = await api.get('/floor-plans', {
            per_page: 50,
            ...(activeBranchUlid.value ? { branch: activeBranchUlid.value } : {}),
        });

        plans.value = lista.data;

        const objetivo = ulid
            ?? props.planUlid
            ?? plans.value.find((p) => p.is_default)?.ulid
            ?? plans.value[0]?.ulid;

        if (! objetivo) {
            plan.value = null;
            tables.value = [];

            return;
        }

        aplicar((await api.get(`/floor-plans/${objetivo}`)).data);
    } catch (e) {
        if (e instanceof ApiError) {
            loadError.value = e;
        } else {
            throw e;
        }
    } finally {
        loading.value = false;
    }
}

/** Reemplaza lo que hay en pantalla con lo que dice el servidor, y limpia el borrador. */
function aplicar(datos) {
    plan.value = datos;

    // Copia propia de la geometría: se edita en memoria, y mutar la respuesta del servidor haría imposible saber qué
    // se ha cambiado y qué no.
    tables.value = (datos.tables ?? []).map((m) => ({ ...m, geometry: { ...m.geometry } }));
    elements.value = (datos.elements ?? []).map((e) => ({ ...e, geometry: { ...e.geometry } }));

    dirty.value = false;
    conflicto.value = null;

    // El plano recién cargado es el nuevo punto de partida: no hay nada que deshacer hacia atrás.
    undoStack.value = [];
    redoStack.value = [];
}

// ---------------------------------------------------------------- Deshacer / rehacer

/** El estado editable en una cadena: geometría de cada mesa, su zona y el tamaño del lienzo. */
function serializar() {
    return JSON.stringify({
        canvas: { width: plan.value.canvas.width, height: plan.value.canvas.height },
        tables: tables.value.map((m) => ({
            ulid: m.ulid,
            x: m.geometry.x, y: m.geometry.y,
            width: m.geometry.width, height: m.geometry.height,
            rotation: m.geometry.rotation, shape: m.geometry.shape,
            zoneUlid: m.zone?.ulid,
        })),
        elements: elements.value.map((e) => ({
            ulid: e.ulid,
            x: e.geometry.x, y: e.geometry.y,
            width: e.geometry.width, height: e.geometry.height,
            rotation: e.geometry.rotation,
        })),
    });
}

function aplicarSnapshot(cadena) {
    const datos = JSON.parse(cadena);

    plan.value.canvas.width = datos.canvas.width;
    plan.value.canvas.height = datos.canvas.height;

    for (const fila of datos.tables) {
        const mesa = tables.value.find((m) => m.ulid === fila.ulid);

        if (! mesa) {
            continue;
        }

        Object.assign(mesa.geometry, {
            x: fila.x, y: fila.y, width: fila.width, height: fila.height, rotation: fila.rotation, shape: fila.shape,
        });

        if (fila.zoneUlid && mesa.zone?.ulid !== fila.zoneUlid) {
            const zona = plan.value.zones.find((z) => z.ulid === fila.zoneUlid);
            if (zona) {
                mesa.zone = { ulid: zona.ulid, name: zona.name };
            }
        }
    }

    for (const fila of (datos.elements ?? [])) {
        const el = elements.value.find((e) => e.ulid === fila.ulid);

        if (el) {
            Object.assign(el.geometry, {
                x: fila.x, y: fila.y, width: fila.width, height: fila.height, rotation: fila.rotation,
            });
        }
    }

    dirty.value = true;
}

/**
 * Guarda el estado ANTES de una mutación, agrupando por gesto: se ignora si han pasado menos de 400 ms desde la última
 * llamada, así un arrastre entero —o los tres ajustes que hace «Forma»— cuentan como un solo paso de deshacer.
 */
let ultimaCaptura = 0;
function capturar() {
    const ahora = Date.now();
    const nuevo = ahora - ultimaCaptura > 400;
    ultimaCaptura = ahora;

    if (! nuevo) {
        return;
    }

    undoStack.value = [...undoStack.value.slice(-49), serializar()];
    redoStack.value = [];
}

function deshacer() {
    if (! undoStack.value.length) {
        return;
    }

    redoStack.value = [...redoStack.value, serializar()];
    const previo = undoStack.value[undoStack.value.length - 1];
    undoStack.value = undoStack.value.slice(0, -1);
    aplicarSnapshot(previo);
}

function rehacer() {
    if (! redoStack.value.length) {
        return;
    }

    undoStack.value = [...undoStack.value, serializar()];
    const siguiente = redoStack.value[redoStack.value.length - 1];
    redoStack.value = redoStack.value.slice(0, -1);
    aplicarSnapshot(siguiente);
}

function mover({ ulid, x, y }) {
    const mesa = tables.value.find((m) => m.ulid === ulid);

    if (! mesa) {
        return;
    }

    capturar();
    mesa.geometry.x = x.toFixed(2);
    mesa.geometry.y = y.toFixed(2);
    dirty.value = true;
}

/** Redimensionar con el tirador de la esquina: el equivalente visual de teclear ancho y alto. */
function redimensionar({ ulid, width, height }) {
    const mesa = tables.value.find((m) => m.ulid === ulid);

    if (! mesa) {
        return;
    }

    capturar();
    mesa.geometry.width = Number(width).toFixed(2);
    mesa.geometry.height = Number(height).toFixed(2);
    dirty.value = true;
}

/** Los campos de la mesa seleccionada, que es como se hace lo que el ratón hace mal: girar y afinar medidas. */
const mesaSeleccionada = computed(() => tables.value.find((m) => m.ulid === selected.value) ?? null);

function ajustar(campo, valor) {
    if (! mesaSeleccionada.value) {
        return;
    }

    capturar();
    mesaSeleccionada.value.geometry[campo] = valor;
    dirty.value = true;
}

// ---- Elementos decorativos (ADR-011): mismos gestos que las mesas, sobre `elements` ----

const elementoSeleccionado = computed(() => elements.value.find((e) => e.ulid === selectedEl.value) ?? null);

function moverElemento({ ulid, x, y }) {
    const el = elements.value.find((e) => e.ulid === ulid);

    if (! el) {
        return;
    }

    capturar();
    el.geometry.x = x.toFixed(2);
    el.geometry.y = y.toFixed(2);
    dirty.value = true;
}

function redimensionarElemento({ ulid, width, height }) {
    const el = elements.value.find((e) => e.ulid === ulid);

    if (! el) {
        return;
    }

    capturar();
    el.geometry.width = Number(width).toFixed(2);
    el.geometry.height = Number(height).toFixed(2);
    dirty.value = true;
}

function ajustarElemento(campo, valor) {
    if (! elementoSeleccionado.value) {
        return;
    }

    capturar();
    elementoSeleccionado.value.geometry[campo] = valor;
    dirty.value = true;
}

function stepDimEl(campo, delta) {
    const g = elementoSeleccionado.value?.geometry;

    if (! g) {
        return;
    }

    ajustarElemento(campo, Math.max(30, Number(g[campo] || 0) + delta).toFixed(2));
}

function stepRotEl(delta) {
    const g = elementoSeleccionado.value?.geometry;

    if (! g) {
        return;
    }

    let r = (Number(g.rotation || 0) + delta) % 360;

    if (r < 0) {
        r += 360;
    }

    ajustarElemento('rotation', r.toFixed(2));
}

/** El cuerpo del guardado en bloque: canvas + geometría de cada mesa. Compartido por «Guardar» y el alta. */
function cuerpoLayout() {
    return {
        version: plan.value.version,
        canvas: { width: plan.value.canvas.width, height: plan.value.canvas.height },
        tables: tables.value.map((m) => ({
            ulid: m.ulid,
            zone_ulid: m.zone?.ulid,
            x: m.geometry.x,
            y: m.geometry.y,
            width: m.geometry.width,
            height: m.geometry.height,
            rotation: m.geometry.rotation,
            shape: m.geometry.shape,
        })),
        elements: elements.value.map((e) => ({
            ulid: e.ulid,
            x: e.geometry.x,
            y: e.geometry.y,
            width: e.geometry.width,
            height: e.geometry.height,
            rotation: e.geometry.rotation,
        })),
    };
}

/** Persiste el layout. Devuelve `true` si guardó; si hubo conflicto lo publica y devuelve `false`. */
async function persistirLayout() {
    try {
        aplicar((await api.put(`/floor-plans/${plan.value.ulid}/layout`, cuerpoLayout())).data);

        return true;
    } catch (e) {
        // El 409 no es un fallo: es otra persona trabajando. Se guarda su plano para poder enseñarlo.
        if (e instanceof ApiError && e.status === 409 && e.payload?.type === 'version_conflict') {
            conflicto.value = e.payload;

            return false;
        }

        throw e;
    }
}

const guardar = useApiForm(persistirLayout);

/** Descartar lo propio y quedarse con lo que hay. */
function aceptarDelOtro() {
    aplicar(conflicto.value.data);
}

/** Reaplicar lo propio encima de la versión nueva. Sigue siendo una decisión de quien edita, no del sistema. */
function reaplicar() {
    const version = conflicto.value.current_version;

    plan.value = { ...plan.value, version };
    conflicto.value = null;
    dirty.value = true;
}

// ---------------------------------------------------------------- Añadir mesa

const agregando = ref(false);
const nuevaMesa = reactive({ zoneUlid: '', code: '', seats: 4, preset: 'estandar' });

/** El siguiente código libre estilo «M7»: mirando los que ya hay, no un contador que se desincroniza al retirar mesas. */
function siguienteCodigo() {
    const numeros = tables.value
        .map((m) => /^M?(\d+)$/i.exec(String(m.code)))
        .filter(Boolean)
        .map((coincidencia) => Number(coincidencia[1]));

    return `M${numeros.length ? Math.max(...numeros) + 1 : 1}`;
}

function abrirAgregar() {
    const preset = PRESETS.find((p) => p.key === 'estandar');

    // Por omisión, la zona que se está viendo: añadir una mesa mientras editas la Terraza la pone en la Terraza.
    nuevaMesa.zoneUlid = zonaActiva.value ?? plan.value?.zones?.[0]?.ulid ?? '';
    nuevaMesa.preset = preset.key;
    nuevaMesa.seats = preset.seats;
    nuevaMesa.code = siguienteCodigo();

    agregando.value = true;
}

/** Al cambiar de preset, los asientos siguen la sugerencia del preset (el humano los ajusta si quiere). */
function elegirPreset(key) {
    const preset = PRESETS.find((p) => p.key === key);

    nuevaMesa.preset = key;
    nuevaMesa.seats = preset.seats;
}

const agregarMesa = useApiForm(async () => {
    const preset = PRESETS.find((p) => p.key === nuevaMesa.preset) ?? PRESETS[1];

    // El alta recarga el plano; primero se persiste el acomodo pendiente para no perderlo. Si eso choca, se enseña el
    // conflicto y el alta se pospone: no se puede colocar una mesa sobre un plano que ya no es el que se veía.
    if (dirty.value && ! (await persistirLayout())) {
        return;
    }

    const creada = (await api.post('/restaurant-tables', {
        floor_zone_ulid: nuevaMesa.zoneUlid,
        code: nuevaMesa.code,
        seats: nuevaMesa.seats,
        shape: preset.shape,
    })).data;

    // Nace 80×80 en la esquina (0,0). Se coloca en el centro con el tamaño del preset para que aparezca a la vista,
    // lista para arrastrar donde vaya.
    const x = Math.max(0, (Number(plan.value.canvas.width) - preset.width) / 2);
    const y = Math.max(0, (Number(plan.value.canvas.height) - preset.height) / 2);

    await api.patch(`/restaurant-tables/${creada.ulid}`, {
        x: x.toFixed(2),
        y: y.toFixed(2),
        width: preset.width,
        height: preset.height,
    });

    agregando.value = false;
    await load(plan.value.ulid);
    selected.value = creada.ulid;
});

// ---------------------------------------------------------------- Datos y retiro de la mesa

/** Nombre y asientos NO viajan en el guardado del layout (que es sólo geometría): se editan con su propio PATCH. */
const guardarDatos = useApiForm(async () => {
    const mesa = mesaSeleccionada.value;

    await api.patch(`/restaurant-tables/${mesa.ulid}`, {
        name: mesa.name ?? null,
        seats: Number(mesa.seats),
    });

    await load(plan.value.ulid);
    selected.value = mesa.ulid;
});

const archivar = useApiForm(async () => {
    const mesa = mesaSeleccionada.value;
    const accion = mesa.is_archived ? 'restore' : 'archive';

    await api.post(`/restaurant-tables/${mesa.ulid}/${accion}`);
    await load(plan.value.ulid);
});

// ---------------------------------------------------------------- Duplicar

/** Duplica la mesa seleccionada: misma forma, tamaño y zona, con código nuevo y desplazada para no taparla. */
const duplicar = useApiForm(async () => {
    const orig = mesaSeleccionada.value;

    if (! orig) {
        return;
    }

    // Igual que el alta: recarga el plano, así que se persiste el acomodo pendiente antes.
    if (dirty.value && ! (await persistirLayout())) {
        return;
    }

    const creada = (await api.post('/restaurant-tables', {
        floor_zone_ulid: orig.zone?.ulid,
        code: siguienteCodigo(),
        name: orig.name ?? null,
        seats: Number(orig.seats),
        shape: orig.geometry.shape,
    })).data;

    const w = Number(orig.geometry.width);
    const h = Number(orig.geometry.height);
    const x = Math.min(Math.max(0, Number(orig.geometry.x) + 30), Number(plan.value.canvas.width) - w);
    const y = Math.min(Math.max(0, Number(orig.geometry.y) + 30), Number(plan.value.canvas.height) - h);

    await api.patch(`/restaurant-tables/${creada.ulid}`, {
        x: x.toFixed(2),
        y: y.toFixed(2),
        width: w,
        height: h,
        rotation: Number(orig.geometry.rotation),
    });

    await load(plan.value.ulid);
    selected.value = creada.ulid;
});

// ---------------------------------------------------------------- Forma, medidas y zona de la mesa

/** Tres opciones de UI (Cuadrada/Redonda/Rectangular) sobre las DOS formas del dato (rectangle/circle). */
const FORMAS = [
    { key: 'cuadrada', label: 'Cuadrada', icon: 'square' },
    { key: 'redonda', label: 'Redonda', icon: 'circle' },
    { key: 'rectangular', label: 'Rectangular', icon: 'rect' },
];

const formaActual = computed(() => {
    const g = mesaSeleccionada.value?.geometry;

    if (! g) {
        return null;
    }

    if (g.shape === 'circle') {
        return 'redonda';
    }

    return Number(g.width) === Number(g.height) ? 'cuadrada' : 'rectangular';
});

function setForma(key) {
    const g = mesaSeleccionada.value?.geometry;

    if (! g) {
        return;
    }

    if (key === 'redonda') {
        ajustar('shape', 'circle');
    } else if (key === 'cuadrada') {
        ajustar('shape', 'rectangle');
        const lado = Math.min(Number(g.width), Number(g.height));
        ajustar('width', lado.toFixed(2));
        ajustar('height', lado.toFixed(2));
    } else {
        ajustar('shape', 'rectangle');
        // Si venía cuadrada, se le da proporción para que «rectangular» se note.
        if (Number(g.width) === Number(g.height)) {
            ajustar('width', (Number(g.height) * 1.6).toFixed(2));
        }
    }
}

/** Botones ± de las dimensiones (mínimo 30 cm, el mismo que el tirador). */
function stepDim(campo, delta) {
    const g = mesaSeleccionada.value?.geometry;

    if (! g) {
        return;
    }

    ajustar(campo, Math.max(30, Number(g[campo] || 0) + delta).toFixed(2));
}

/** Girar ± con vuelta (0–359), para no salir del rango que acepta el servidor. */
function stepRot(delta) {
    const g = mesaSeleccionada.value?.geometry;

    if (! g) {
        return;
    }

    let r = (Number(g.rotation || 0) + delta) % 360;

    if (r < 0) {
        r += 360;
    }

    ajustar('rotation', r.toFixed(2));
}

/** Capacidad ± (1–99). Local; se persiste con «Guardar datos», como el nombre. */
function stepSeats(delta) {
    const mesa = mesaSeleccionada.value;

    if (! mesa) {
        return;
    }

    mesa.seats = Math.min(99, Math.max(1, Number(mesa.seats || 0) + delta));
}

/** Reasigna la zona de la mesa. Persiste con el guardado del layout, que sí reubica zonas. */
function asignarZona(zona) {
    const mesa = mesaSeleccionada.value;

    if (! mesa || ! zona) {
        return;
    }

    capturar();
    mesa.zone = { ulid: zona.ulid, name: zona.name };
    dirty.value = true;
}

/** El tamaño del lienzo, con captura para poder deshacerlo. */
function ajustarCanvas(campo, valor) {
    capturar();
    plan.value.canvas[campo] = valor;
    dirty.value = true;
}

// ---------------------------------------------------------------- Alinear (a la cuadrícula)

const alinearAbierto = ref(false);

/**
 * Ajusta posiciones y medidas a una cuadrícula de 10 cm — la manera de «alinear» que tiene sentido con una sola mesa
 * seleccionada: ordena sin destruir la distribución que el usuario armó a mano.
 */
function ajustarCuadricula(soloSeleccion) {
    const paso = 10;
    const snap = (v) => (Math.round(Number(v) / paso) * paso).toFixed(2);
    const objetivo = soloSeleccion
        ? tables.value.filter((m) => m.ulid === selected.value)
        : tables.value;

    if (! objetivo.length) {
        alinearAbierto.value = false;
        return;
    }

    capturar();

    for (const mesa of objetivo) {
        mesa.geometry.x = snap(mesa.geometry.x);
        mesa.geometry.y = snap(mesa.geometry.y);
        mesa.geometry.width = snap(mesa.geometry.width);
        mesa.geometry.height = snap(mesa.geometry.height);
    }

    dirty.value = true;
    alinearAbierto.value = false;
}

// ---------------------------------------------------------------- Zonas

const nuevaZona = ref('');

/**
 * Reordenar zonas arrastrando. El orden es el que verá quien atienda —en la barra de zonas y en los selectores—, así que
 * se persiste al soltar: renumera (10, 20, 30…) y hace PATCH sólo de las que cambiaron. Como las demás acciones de zona,
 * recarga el plano después para que geometría y orden queden consistentes.
 */
const dragZona = useReorder();

async function soltarZona(index) {
    const nuevas = dragZona.reorder(index, plan.value.zones);
    if (! nuevas) return;

    zonaError.value = null;

    try {
        const cambios = nuevas
            .map((z, i) => ({ ulid: z.ulid, sort_order: (i + 1) * 10, antes: Number(z.sort_order ?? 0) }))
            .filter((c) => c.sort_order !== c.antes);

        await Promise.all(cambios.map((c) => api.patch(`/floor-zones/${c.ulid}`, { sort_order: c.sort_order })));
        await load(plan.value.ulid);
    } catch (e) {
        if (e instanceof ApiError) {
            zonaError.value = e.title;
        } else {
            throw e;
        }
    }
}

const crearZona = useApiForm(async () => {
    zonaError.value = null;
    await api.post(`/floor-plans/${plan.value.ulid}/zones`, { name: nuevaZona.value });
    nuevaZona.value = '';
    await load(plan.value.ulid);
});

/** Renombrar una zona. `@change` dispara al salir del campo o con Enter. */
async function renombrarZona(zona, nombre) {
    const limpio = String(nombre).trim();

    if (! limpio || limpio === zona.name) {
        return;
    }

    zonaError.value = null;

    try {
        await api.patch(`/floor-zones/${zona.ulid}`, { name: limpio });
        await load(plan.value.ulid);
    } catch (e) {
        if (e instanceof ApiError) {
            zonaError.value = e.title;
        } else {
            throw e;
        }
    }
}

/** Eliminar una zona. El servidor la rechaza si tiene mesas; el mensaje se muestra tal cual. */
async function eliminarZona(zona) {
    zonaError.value = null;

    try {
        await api.delete(`/floor-zones/${zona.ulid}`);

        if (zonaActiva.value === zona.ulid) {
            zonaActiva.value = null;
        }

        await load(plan.value.ulid);
    } catch (e) {
        if (e instanceof ApiError) {
            zonaError.value = e.title;
        } else {
            throw e;
        }
    }
}

// ---------------------------------------------------------------- Elementos decorativos (ADR-011)

const ELEMENTOS = [
    { key: 'wall', label: 'Muro', icon: 'M4 8h16M4 8v8M20 8v8M4 16h16' },
    { key: 'door', label: 'Puerta', icon: 'M7 21V4h8v17M15 6l2 1v14M10 13h.01' },
    { key: 'label', label: 'Rótulo', icon: 'M4 7h16M4 12h10M4 17h7' },
];
const elementosMenuAbierto = ref(false);
const elementoError = ref(null);
const agregandoElemento = ref(false);

async function agregarElemento(kind) {
    if (agregandoElemento.value) {
        return;
    }

    elementosMenuAbierto.value = false;
    agregandoElemento.value = true;
    elementoError.value = null;

    try {
        // Como el alta de mesa: recarga el plano, así que se persiste el acomodo pendiente antes.
        if (dirty.value && ! (await persistirLayout())) {
            return;
        }

        const creado = (await api.post(`/floor-plans/${plan.value.ulid}/elements`, { kind })).data;

        await load(plan.value.ulid);
        selectedEl.value = creado.ulid;
        selected.value = null;
    } catch (e) {
        if (e instanceof ApiError) {
            elementoError.value = e.title;
        } else {
            throw e;
        }
    } finally {
        agregandoElemento.value = false;
    }
}

async function eliminarElemento() {
    const el = elementoSeleccionado.value;

    if (! el) {
        return;
    }

    elementoError.value = null;

    try {
        await api.delete(`/floor-elements/${el.ulid}`);
        selectedEl.value = null;
        await load(plan.value.ulid);
    } catch (e) {
        if (e instanceof ApiError) {
            elementoError.value = e.title;
        } else {
            throw e;
        }
    }
}

/** El texto de un rótulo se guarda con su propio PATCH (no viaja en el layout, que es sólo geometría). */
async function guardarTextoElemento(texto) {
    const el = elementoSeleccionado.value;

    if (! el) {
        return;
    }

    try {
        await api.patch(`/floor-elements/${el.ulid}`, { text: texto });
        el.text = texto;
    } catch (e) {
        if (e instanceof ApiError) {
            elementoError.value = e.title;
        } else {
            throw e;
        }
    }
}

// ---------------------------------------------------------------- Imprimir

/**
 * Imprimir el plano en una ventana propia.
 *
 * Se lleva el SVG tal como está en pantalla —así lo impreso es lo que se ve, sin un segundo render que pudiera diferir
 * (ADR-003)— más una lista de mesas. Se hace en ventana aparte para no pelear con el shell de administración: la
 * alternativa, ocultar media interfaz con `@media print`, es frágil y se rompe al menor cambio del layout.
 */
async function imprimir() {
    // El tirador de redimensionar sólo existe en la mesa seleccionada; se quita para que no salga un punto suelto en el
    // papel, y se restaura después.
    const previa = selected.value;
    selected.value = null;
    await nextTick();

    const svg = document.querySelector('.editor .lienzo');
    const ventana = window.open('', '_blank', 'width=1000,height=800');

    selected.value = previa;

    if (! svg || ! ventana) {
        return;
    }

    const filas = tables.value
        .filter((m) => ! m.is_archived)
        .sort((a, b) => String(a.code).localeCompare(String(b.code), 'es', { numeric: true }))
        .map((m) => `<tr><td>${m.code}</td><td>${m.name ?? ''}</td><td>${m.seats}</td>`
            + `<td>${m.zone?.name ?? '—'}</td>`
            + `<td>${m.geometry.shape === 'circle' ? 'Redonda' : 'Rectangular'}</td></tr>`)
        .join('');

    const totalMesas = tables.value.filter((m) => ! m.is_archived).length;
    const totalLugares = tables.value
        .filter((m) => ! m.is_archived)
        .reduce((suma, m) => suma + Number(m.seats || 0), 0);

    ventana.document.write(`<!doctype html><html lang="es"><head><meta charset="utf-8">
        <title>Plano — ${plan.value.name}</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: system-ui, sans-serif; color: #1a1a1a; margin: 24px; }
            h1 { font-size: 1.3rem; margin: 0 0 0.25rem; }
            .sub { color: #666; margin: 0 0 1rem; font-size: 0.9rem; }
            svg { width: 100%; height: auto; border: 1px solid #ccc; border-radius: 6px; }
            table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 0.85rem; }
            th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
            th { background: #f5f5f5; }
            @media print { body { margin: 0; } }
        </style></head><body>
        <h1>${plan.value.name}</h1>
        <p class="sub">${totalMesas} mesas · ${totalLugares} lugares · ${plan.value.canvas.width}×${plan.value.canvas.height} cm</p>
        ${svg.outerHTML}
        <table>
            <thead><tr><th>Código</th><th>Nombre</th><th>Lugares</th><th>Zona</th><th>Forma</th></tr></thead>
            <tbody>${filas}</tbody>
        </table>
    </body></html>`);

    ventana.document.close();
    ventana.focus();
    ventana.print();
}
</script>

<template>
    <Head title="Editor del salón" />

    <div class="editor">
        <header class="page-header">
            <div>
                <h1>Editor del salón</h1>
                <p class="page-header__hint">
                    <strong>Diseña y organiza tu salón.</strong> Este plano se usa en el Punto de Venta (POS).
                </p>
            </div>

            <div class="editor__cabecera-acciones">
                <span v-if="dirty" class="editor__borrador">● Cambios sin guardar</span>

                <label v-if="plans.length > 1" class="editor__planos">
                    <span class="section-label">Plano</span>
                    <select :value="plan?.ulid" @change="load($event.target.value)">
                        <option v-for="p in plans" :key="p.ulid" :value="p.ulid">
                            {{ p.name }}{{ p.is_default ? ' (por omisión)' : '' }}
                        </option>
                    </select>
                </label>
            </div>
        </header>

        <p v-if="loading">Cargando…</p>
        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <p v-else-if="!plan" class="nota">
            Esta sucursal todavía no tiene un plano de salón. Créalo desde la pantalla de sucursales.
        </p>

        <template v-else>
            <!-- BARRA DE HERRAMIENTAS. Primaria = añadir; el resto, secundarios neutros del mismo peso. -->
            <div class="barra tarjeta">
                <button type="button" class="button" :disabled="!plan.zones?.length" @click="abrirAgregar">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                    </svg>
                    Añadir mesa
                </button>

                <!-- Agregar elemento decorativo (ADR-011): muro, puerta o rótulo. -->
                <div class="alinear">
                    <button type="button" class="button button--neutral" :disabled="agregandoElemento" @click="elementosMenuAbierto = !elementosMenuAbierto">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 8v8M20 8v8M4 16h16" />
                        </svg>
                        Elemento
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div v-if="elementosMenuAbierto" class="alinear__menu">
                        <button v-for="el in ELEMENTOS" :key="el.key" type="button" @click="agregarElemento(el.key)">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" style="vertical-align:-3px;margin-right:.4rem">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="el.icon" />
                            </svg>
                            {{ el.label }}
                        </button>
                    </div>
                </div>

                <!-- Duplicar aparece SÓLO con una mesa seleccionada: es una acción sobre ella. -->
                <button
                    v-if="mesaSeleccionada"
                    type="button"
                    class="button button--neutral"
                    :disabled="duplicar.processing.value"
                    @click="duplicar.submit()"
                >
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7">
                        <rect x="9" y="9" width="11" height="11" rx="2" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 15V5a2 2 0 0 1 2-2h10" />
                    </svg>
                    Duplicar
                </button>

                <button type="button" class="button button--neutral" @click="imprimir">
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4v-6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6h-2M8 14h8v7H8z" />
                    </svg>
                    Imprimir plano
                </button>

                <div class="alinear">
                    <button type="button" class="button button--neutral" @click="alinearAbierto = !alinearAbierto">
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h16" />
                        </svg>
                        Alinear
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div v-if="alinearAbierto" class="alinear__menu">
                        <button type="button" @click="ajustarCuadricula(false)">Ajustar todo a la cuadrícula</button>
                        <button type="button" :disabled="!mesaSeleccionada" @click="ajustarCuadricula(true)">
                            Ajustar la mesa seleccionada
                        </button>
                    </div>
                </div>

                <span class="barra__sep" />

                <div class="historial">
                    <button type="button" class="icon-btn" title="Deshacer" aria-label="Deshacer" :disabled="!puedeDeshacer" @click="deshacer">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3" />
                        </svg>
                    </button>
                    <button type="button" class="icon-btn" title="Rehacer" aria-label="Rehacer" :disabled="!puedeRehacer" @click="rehacer">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15 14 5-5-5-5M20 9H9a5 5 0 0 0 0 10h3" />
                        </svg>
                    </button>
                </div>

                <button type="button" class="button button--neutral" :disabled="!dirty" @click="load(plan.ulid)">
                    Descartar
                </button>

                <button
                    type="button"
                    class="button"
                    :disabled="guardar.processing.value || !dirty"
                    @click="guardar.submit()"
                >
                    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Guardar el salón
                </button>
            </div>

            <p v-if="duplicar.generalError.value" class="error">{{ duplicar.generalError.value }}</p>
            <p v-if="elementoError" class="error">{{ elementoError }}</p>

            <p v-if="!plan.zones?.length" class="nota">
                Crea una zona antes de añadir mesas: toda mesa vive en una zona del salón.
            </p>

            <p v-if="guardar.generalError.value" class="error">{{ guardar.generalError.value }}</p>

            <!-- EL ALTA. Elegir zona y preset; el código se sugiere y se puede cambiar. -->
            <section v-if="agregando" class="alta tarjeta">
                <h2>Añadir mesa</h2>

                <div class="alta__campos">
                    <label>
                        Zona
                        <select v-model="nuevaMesa.zoneUlid">
                            <option v-for="z in plan.zones" :key="z.ulid" :value="z.ulid">{{ z.name }}</option>
                        </select>
                    </label>

                    <label>
                        Código
                        <input v-model="nuevaMesa.code" type="text" maxlength="10" />
                    </label>

                    <label>
                        Lugares
                        <input v-model.number="nuevaMesa.seats" type="number" min="1" max="99" />
                    </label>
                </div>

                <fieldset class="presets">
                    <legend>Tamaño y forma</legend>

                    <button
                        v-for="p in PRESETS"
                        :key="p.key"
                        type="button"
                        class="preset"
                        :class="{ 'preset--activo': nuevaMesa.preset === p.key }"
                        @click="elegirPreset(p.key)"
                    >
                        <span class="preset__figura" :class="`preset__figura--${p.shape}`" aria-hidden="true" />
                        <span class="preset__nombre">{{ p.label }}</span>
                        <span class="preset__medida">{{ p.width }}×{{ p.height }}</span>
                    </button>
                </fieldset>

                <p v-if="agregarMesa.fieldErrors.value.code" class="error">{{ agregarMesa.fieldErrors.value.code }}</p>
                <p v-else-if="agregarMesa.generalError.value" class="error">{{ agregarMesa.generalError.value }}</p>

                <div class="alta__acciones">
                    <button
                        type="button"
                        class="button"
                        :disabled="agregarMesa.processing.value || !nuevaMesa.zoneUlid || !nuevaMesa.code"
                        @click="agregarMesa.submit()"
                    >
                        Crear mesa
                    </button>
                    <button type="button" class="link-button" @click="agregando = false">Cancelar</button>
                </div>
            </section>

            <!-- EL CONFLICTO. Se enseña y se decide; resolverlo solo sería inventarse cuál salón es el bueno. -->
            <section v-if="conflicto" class="conflicto">
                <h2>Alguien más guardó este plano</h2>

                <p>
                    {{ conflicto.title }} La versión que hay ahora es la {{ conflicto.current_version }}; tú venías de
                    la {{ plan.version }}.
                </p>

                <div class="conflicto__acciones">
                    <button type="button" class="button button--ghost" @click="aceptarDelOtro">
                        Quedarme con lo que hay
                    </button>
                    <button type="button" class="button" @click="reaplicar">Volver a aplicar lo mío encima</button>
                </div>
            </section>

            <div class="editor__cuerpo">
                <div class="editor__col">
                <FloorCanvas
                    :canvas="plan.canvas"
                    :tables="mesasVisibles"
                    :selected="selected"
                    :elements="elements"
                    :selected-element="selectedEl"
                    :zones="plan.zones"
                    color-by="zone"
                    zoomable
                    @select="selected = $event; selectedEl = null"
                    @move="mover"
                    @resize="redimensionar"
                    @element-select="selectedEl = $event; selected = null"
                    @element-move="moverElemento"
                    @element-resize="redimensionarElemento"
                />

                <!-- BARRA DE ZONAS, debajo del lienzo: la zona activa, cambiar entre zonas/todas y gestionarlas. -->
                <div class="zonabar tarjeta">
                    <div class="zonabar__tabs">
                        <button type="button" class="ztab" :class="{ 'ztab--activa': zonaActiva === null }" @click="zonaActiva = null">
                            Todas
                        </button>
                        <button
                            v-for="(z, i) in plan.zones"
                            :key="z.ulid"
                            type="button"
                            class="ztab"
                            :class="{ 'ztab--activa': zonaActiva === z.ulid }"
                            @click="zonaActiva = z.ulid"
                        >
                            <span class="ztab__pt" :style="{ background: colorZona(i) }" aria-hidden="true" />
                            {{ z.name }}
                        </button>
                    </div>

                    <button type="button" class="button button--neutral" @click="gestionAbierta = !gestionAbierta">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7">
                            <circle cx="12" cy="12" r="3.2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5v2M12 18.5v2M4.2 7.5l1.7 1M18.1 15.5l1.7 1M20.5 7.5l-1.7 1M5.9 15.5l-1.7 1" />
                        </svg>
                        Gestionar
                    </button>
                </div>

                <!-- Gestión: tamaño del salón y zonas. Aparte del panel de la mesa para no mezclar utilidades distintas. -->
                <section v-if="gestionAbierta" class="gestion tarjeta">
                    <div class="gestion__bloque">
                        <span class="section-label">Tamaño del salón (cm)</span>
                        <div class="geo">
                            <label>
                                Ancho
                                <input :value="plan.canvas.width" inputmode="decimal" @input="ajustarCanvas('width', $event.target.value)" />
                            </label>
                            <label>
                                Alto
                                <input :value="plan.canvas.height" inputmode="decimal" @input="ajustarCanvas('height', $event.target.value)" />
                            </label>
                        </div>
                    </div>

                    <div class="gestion__bloque">
                        <span class="section-label">Zonas</span>
                        <p v-if="zonaError" class="error">{{ zonaError }}</p>
                        <ul class="zona-lista">
                            <li
                                v-for="(z, i) in plan.zones"
                                :key="z.ulid"
                                :class="{ 'zona-lista__row--over': dragZona.over.value === i && dragZona.from.value !== i }"
                                @dragover.prevent="dragZona.enter(i)"
                                @drop="soltarZona(i)"
                                @dragend="dragZona.end()"
                            >
                                <span
                                    class="zona-lista__handle"
                                    draggable="true"
                                    title="Arrastra para reordenar"
                                    aria-label="Arrastra para reordenar"
                                    @dragstart="dragZona.start(i)"
                                >
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor">
                                        <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                                        <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                                        <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                                    </svg>
                                </span>
                                <span class="ztab__pt" :style="{ background: colorZona(i) }" aria-hidden="true" />
                                <input class="zona-lista__nombre" :value="z.name" @change="renombrarZona(z, $event.target.value)" />
                                <button type="button" class="icon-btn icon-btn--danger" title="Eliminar zona" aria-label="Eliminar zona" @click="eliminarZona(z)">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" />
                                    </svg>
                                </button>
                            </li>
                        </ul>
                        <form class="zona-nueva" @submit.prevent="crearZona.submit()">
                            <input v-model="nuevaZona" type="text" placeholder="Nueva zona (p. ej. Terraza)" required />
                            <button type="submit" class="button button--neutral" :disabled="crearZona.processing.value">Agregar</button>
                        </form>
                        <p v-if="crearZona.generalError.value" class="error">{{ crearZona.generalError.value }}</p>
                    </div>
                </section>
                </div>

                <aside class="panel tarjeta">
                    <!-- Panel del ELEMENTO seleccionado (muro/puerta/rótulo) -->
                    <template v-if="elementoSeleccionado">
                        <h2 class="panel__titulo">Elemento seleccionado</h2>

                        <div class="mesa-id">
                            <span class="mesa-id__punto" style="background:#9aa0a6" aria-hidden="true" />
                            <div class="mesa-id__texto">
                                <strong class="mesa-id__code">{{ ELEMENTOS.find((e) => e.key === elementoSeleccionado.kind)?.label ?? 'Elemento' }}</strong>
                            </div>
                        </div>

                        <label v-if="elementoSeleccionado.kind === 'label'" class="campo">
                            <span class="section-label">Texto</span>
                            <input
                                :value="elementoSeleccionado.text"
                                maxlength="120"
                                placeholder="Escribe el rótulo"
                                @change="guardarTextoElemento($event.target.value)"
                            />
                        </label>

                        <div class="grupo">
                            <span class="section-label">Dimensiones (cm)</span>
                            <div class="dims">
                                <div class="stepper">
                                    <button type="button" aria-label="Menos ancho" @click="stepDimEl('width', -10)">−</button>
                                    <input :value="elementoSeleccionado.geometry.width" inputmode="decimal" @input="ajustarElemento('width', $event.target.value)" />
                                    <button type="button" aria-label="Más ancho" @click="stepDimEl('width', 10)">+</button>
                                </div>
                                <span class="dims__x">×</span>
                                <div class="stepper">
                                    <button type="button" aria-label="Menos alto" @click="stepDimEl('height', -10)">−</button>
                                    <input :value="elementoSeleccionado.geometry.height" inputmode="decimal" @input="ajustarElemento('height', $event.target.value)" />
                                    <button type="button" aria-label="Más alto" @click="stepDimEl('height', 10)">+</button>
                                </div>
                            </div>
                            <div class="dims__rot">
                                <span class="dims__rot-label">Rotación</span>
                                <div class="stepper stepper--sm">
                                    <button type="button" aria-label="Girar a la izquierda" @click="stepRotEl(-15)">−</button>
                                    <input :value="elementoSeleccionado.geometry.rotation" inputmode="decimal" @input="ajustarElemento('rotation', $event.target.value)" />
                                    <button type="button" aria-label="Girar a la derecha" @click="stepRotEl(15)">+</button>
                                </div>
                                <span class="dims__rot-unit">°</span>
                            </div>
                        </div>

                        <button type="button" class="btn-eliminar panel__ancho" @click="eliminarElemento">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" />
                            </svg>
                            Eliminar elemento
                        </button>
                    </template>

                    <!-- Panel de la MESA seleccionada -->
                    <template v-else>
                    <h2 class="panel__titulo">Mesa seleccionada</h2>

                    <p v-if="!mesaSeleccionada" class="nota">Toca una mesa o un elemento para editarlo, o arrástralo para moverlo.</p>

                    <template v-else>
                        <!-- Identidad de la mesa -->
                        <div class="mesa-id">
                            <span class="mesa-id__punto" aria-hidden="true" />
                            <div class="mesa-id__texto">
                                <strong class="mesa-id__code">{{ mesaSeleccionada.code }}</strong>
                                <span class="mesa-id__sub">{{ FORMAS.find((f) => f.key === formaActual)?.label ?? 'Mesa' }}</span>
                            </div>
                            <span v-if="mesaSeleccionada.is_archived" class="etiqueta-baja">retirada</span>
                        </div>

                        <label class="campo">
                            <span class="section-label">Nombre</span>
                            <input
                                :value="mesaSeleccionada.name"
                                type="text"
                                maxlength="60"
                                placeholder="Opcional"
                                @input="mesaSeleccionada.name = $event.target.value"
                            />
                        </label>

                        <!-- Dimensiones con steppers -->
                        <div class="grupo">
                            <span class="section-label">Dimensiones (cm)</span>
                            <div class="dims">
                                <div class="stepper">
                                    <button type="button" aria-label="Menos ancho" @click="stepDim('width', -10)">−</button>
                                    <input :value="mesaSeleccionada.geometry.width" inputmode="decimal" @input="ajustar('width', $event.target.value)" />
                                    <button type="button" aria-label="Más ancho" @click="stepDim('width', 10)">+</button>
                                </div>
                                <span class="dims__x">×</span>
                                <div class="stepper">
                                    <button type="button" aria-label="Menos alto" @click="stepDim('height', -10)">−</button>
                                    <input :value="mesaSeleccionada.geometry.height" inputmode="decimal" @input="ajustar('height', $event.target.value)" />
                                    <button type="button" aria-label="Más alto" @click="stepDim('height', 10)">+</button>
                                </div>
                            </div>
                            <div class="dims__rot">
                                <span class="dims__rot-label">Rotación</span>
                                <div class="stepper stepper--sm">
                                    <button type="button" aria-label="Girar a la izquierda" @click="stepRot(-15)">−</button>
                                    <input :value="mesaSeleccionada.geometry.rotation" inputmode="decimal" @input="ajustar('rotation', $event.target.value)" />
                                    <button type="button" aria-label="Girar a la derecha" @click="stepRot(15)">+</button>
                                </div>
                                <span class="dims__rot-unit">°</span>
                            </div>
                        </div>

                        <!-- Forma: tres opciones con icono -->
                        <div class="grupo">
                            <span class="section-label">Forma</span>
                            <div class="formas">
                                <button
                                    v-for="f in FORMAS"
                                    :key="f.key"
                                    type="button"
                                    class="forma"
                                    :class="{ 'forma--activa': formaActual === f.key }"
                                    @click="setForma(f.key)"
                                >
                                    <span class="forma__fig" :class="`forma__fig--${f.icon}`" aria-hidden="true" />
                                    <span>{{ f.label }}</span>
                                </button>
                            </div>
                        </div>

                        <!-- Capacidad -->
                        <div class="grupo">
                            <span class="section-label">Capacidad</span>
                            <div class="cap">
                                <div class="stepper">
                                    <button type="button" aria-label="Menos lugares" @click="stepSeats(-1)">−</button>
                                    <input :value="mesaSeleccionada.seats" inputmode="numeric" @input="mesaSeleccionada.seats = $event.target.value" />
                                    <button type="button" aria-label="Más lugares" @click="stepSeats(1)">+</button>
                                </div>
                                <span class="cap__unidad">personas</span>
                            </div>
                        </div>

                        <!-- Zona asignada -->
                        <div class="grupo">
                            <span class="section-label">Zona asignada</span>
                            <select
                                :value="mesaSeleccionada.zone?.ulid"
                                @change="asignarZona(plan.zones.find((z) => z.ulid === $event.target.value))"
                            >
                                <option v-for="z in plan.zones" :key="z.ulid" :value="z.ulid">{{ z.name }}</option>
                            </select>
                            <div class="zonas-pills">
                                <button
                                    v-for="z in plan.zones"
                                    :key="z.ulid"
                                    type="button"
                                    class="zona-pill"
                                    :class="{ 'zona-pill--activa': mesaSeleccionada.zone?.ulid === z.ulid }"
                                    @click="asignarZona(z)"
                                >
                                    {{ z.name }}
                                </button>
                            </div>
                        </div>

                        <p v-if="guardarDatos.generalError.value" class="error">{{ guardarDatos.generalError.value }}</p>

                        <button
                            type="button"
                            class="button button--neutral panel__ancho"
                            :disabled="guardarDatos.processing.value"
                            @click="guardarDatos.submit()"
                        >
                            Guardar datos de la mesa
                        </button>

                        <p v-if="archivar.generalError.value" class="error">{{ archivar.generalError.value }}</p>

                        <button
                            type="button"
                            class="btn-eliminar panel__ancho"
                            :disabled="archivar.processing.value"
                            @click="archivar.submit()"
                        >
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" />
                            </svg>
                            {{ mesaSeleccionada.is_archived ? 'Devolver al piso' : 'Retirar mesa' }}
                        </button>
                    </template>
                    </template>
                </aside>
            </div>
        </template>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.editor { display: grid; gap: 1rem; }

.editor__cabecera-acciones { display: flex; align-items: center; gap: 1rem; margin-left: auto; flex-wrap: wrap; }
.editor__borrador { color: var(--color-aviso); font-size: 0.82rem; font-weight: 600; margin: 0; white-space: nowrap; }
.editor__planos { display: grid; gap: 0.15rem; }

.barra {
    display: flex;
    gap: 0.6rem;
    align-items: center;
    flex-wrap: wrap;
    padding: 0.65rem 0.8rem;
}
.barra__sep { flex: 1; }

.alinear { position: relative; }
.alinear__menu {
    position: absolute;
    top: calc(100% + 0.35rem);
    left: 0;
    z-index: 5;
    min-width: 15rem;
    display: flex;
    flex-direction: column;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    box-shadow: 0 8px 24px -8px rgb(0 0 0 / 0.25);
    overflow: hidden;
}
.alinear__menu button {
    text-align: left;
    border: 0;
    background: transparent;
    color: var(--color-contenido);
    font: inherit;
    font-size: 0.85rem;
    padding: 0.55rem 0.8rem;
    cursor: pointer;
}
.alinear__menu button:hover:not(:disabled) { background: color-mix(in srgb, var(--color-acento) 10%, transparent); color: var(--color-acento); }
.alinear__menu button:disabled { opacity: 0.5; cursor: not-allowed; }

.historial { display: inline-flex; gap: 0.3rem; }
.icon-btn {
    display: grid;
    place-items: center;
    width: 2.1rem;
    height: 2.1rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.55rem;
    background: var(--color-superficie);
    color: var(--color-suave);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
}
.icon-btn:hover:not(:disabled) { color: var(--color-acento); border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.icon-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.icon-btn--danger { color: var(--color-peligro); }
.icon-btn--danger:hover:not(:disabled) { color: var(--color-peligro); border-color: color-mix(in srgb, var(--color-peligro) 45%, transparent); background: color-mix(in srgb, var(--color-peligro) 8%, transparent); }

/* Barra de zonas: tabs de zona (activa resaltada) + gestionar. */
.zonabar { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.6rem; flex-wrap: wrap; }
.zonabar__tabs { display: flex; gap: 0.3rem; flex-wrap: wrap; flex: 1; min-width: 0; }
.zonabar > .button { margin-left: auto; flex: none; }
.ztab {
    display: inline-flex; align-items: center; gap: 0.45rem;
    font: inherit; font-size: 0.85rem; padding: 0.4rem 0.85rem; cursor: pointer;
    border: 1px solid transparent; border-radius: 999px;
    background: transparent; color: var(--color-suave);
    transition: background-color 0.15s ease, color 0.15s ease;
}
.ztab:hover { color: var(--color-contenido); background: color-mix(in srgb, var(--color-contenido) 5%, transparent); }
.ztab--activa { background: color-mix(in srgb, var(--color-acento) 14%, transparent); color: var(--color-acento); font-weight: 600; }
.ztab__pt { width: 0.7rem; height: 0.7rem; border-radius: 50%; flex: none; }

/* Panel de gestión: tamaño del salón + zonas, en columnas. */
.gestion { padding: 1rem 1.1rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: 1.5rem; }
.gestion__bloque { display: grid; gap: 0.5rem; align-content: start; }
.zona-lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; }
.zona-lista li { display: flex; align-items: center; gap: 0.5rem; border-radius: 0.4rem; }
.zona-lista__nombre { flex: 1; }
.zona-lista__handle {
    display: inline-grid; place-items: center; color: var(--color-suave);
    cursor: grab; border-radius: 0.3rem; padding: 0.1rem; margin-left: -0.15rem;
}
.zona-lista__handle:hover { color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.zona-lista__handle:active { cursor: grabbing; }
.zona-lista__row--over { box-shadow: inset 0 2px 0 var(--color-acento); }

.editor__cuerpo { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; gap: 1rem; align-items: start; }
/* Columna del lienzo: el salón y, DEBAJO, la barra de zonas y (si se abre) el panel de gestión. */
.editor__col { display: flex; flex-direction: column; gap: 0.75rem; min-width: 0; }

.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
}

.panel { padding: 1rem 1.1rem; display: grid; gap: 0.9rem; align-content: start; }
.panel h2 { font-size: 0.95rem; margin: 0; font-weight: 650; }
.panel__titulo { font-size: 0.72rem !important; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-suave); }
.panel hr { border: 0; border-top: 1px solid var(--color-borde); margin: 0.15rem 0; }

.geo { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }

/* Identidad de la mesa seleccionada. */
.mesa-id { display: flex; align-items: center; gap: 0.6rem; }
.mesa-id__punto { width: 0.6rem; height: 0.6rem; border-radius: 50%; background: var(--color-acento); flex: none; }
.mesa-id__texto { display: flex; flex-direction: column; line-height: 1.2; flex: 1; }
.mesa-id__code { font-size: 1.05rem; font-weight: 650; }
.mesa-id__sub { font-size: 0.78rem; color: var(--color-suave); }

.grupo { display: grid; gap: 0.4rem; }
.campo { display: grid; gap: 0.35rem; }

/* Stepper: [−] input [+] como una sola pieza. */
.stepper { display: inline-flex; align-items: stretch; border: 1px solid var(--color-borde); border-radius: 0.55rem; overflow: hidden; background: var(--color-superficie); }
.stepper button {
    border: 0; background: transparent; color: var(--color-suave); cursor: pointer;
    width: 2rem; font-size: 1.05rem; line-height: 1; display: grid; place-items: center;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.stepper button:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); color: var(--color-acento); }
.stepper input {
    width: 100%; min-width: 0; border: 0 !important; border-radius: 0 !important;
    text-align: center; padding: 0.45rem 0.2rem !important; background: transparent !important;
    font-variant-numeric: tabular-nums;
}
.stepper input:focus-visible { box-shadow: none !important; }
.stepper--sm { max-width: 7rem; }

.dims { display: flex; align-items: center; gap: 0.4rem; }
.dims .stepper { flex: 1; }
.dims__x { color: var(--color-suave); font-weight: 600; }
.dims__rot { display: flex; align-items: center; gap: 0.5rem; }
.dims__rot-label { font-size: 0.8rem; color: var(--color-suave); }
.dims__rot-unit { color: var(--color-suave); }

/* Forma: tres toggles con figura. */
.formas { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.4rem; }
.forma {
    display: grid; justify-items: center; gap: 0.3rem; padding: 0.55rem 0.3rem;
    font: inherit; font-size: 0.72rem; cursor: pointer; color: var(--color-suave);
    background: var(--color-superficie); border: 1px solid var(--color-borde); border-radius: 0.55rem;
    transition: border-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.forma:hover { border-color: var(--color-acento); }
.forma--activa { border-color: var(--color-acento); color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }
.forma__fig { display: block; width: 1.4rem; height: 1.4rem; border: 2px solid currentColor; }
.forma__fig--square { border-radius: 0.2rem; }
.forma__fig--circle { border-radius: 50%; }
.forma__fig--rect { width: 1.7rem; height: 1.1rem; border-radius: 0.2rem; }

.cap { display: flex; align-items: center; gap: 0.6rem; }
.cap .stepper { width: 8rem; }
.cap__unidad { font-size: 0.85rem; color: var(--color-suave); }

/* Zonas: dropdown + pills de acceso rápido. */
.zonas-pills { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.zona-pill {
    font: inherit; font-size: 0.78rem; padding: 0.25rem 0.7rem; cursor: pointer;
    border: 1px solid var(--color-borde); border-radius: 999px;
    background: var(--color-superficie); color: var(--color-suave);
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}
.zona-pill:hover { border-color: var(--color-acento); color: var(--color-acento); }
.zona-pill--activa { background: color-mix(in srgb, var(--color-acento) 12%, transparent); border-color: var(--color-acento); color: var(--color-acento); font-weight: 600; }

.panel__ancho { width: 100%; }

/* Retirar mesa: acción destructiva de borde rojo (retira del piso, no borra). */
.btn-eliminar {
    display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;
    font: inherit; font-size: 0.82rem; font-weight: 600; padding: 0.42rem 0.85rem; cursor: pointer;
    border: 1px solid color-mix(in srgb, var(--color-peligro) 45%, transparent); border-radius: 0.55rem;
    background: transparent; color: var(--color-peligro);
    transition: background-color 0.15s ease;
}
.btn-eliminar:hover:not(:disabled) { background: color-mix(in srgb, var(--color-peligro) 10%, transparent); }
.btn-eliminar:disabled { opacity: 0.5; cursor: not-allowed; }

.alta { padding: 1rem 1.1rem; display: grid; gap: 0.75rem; }
.alta h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }
.alta__campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.6rem; }
.alta__acciones { display: flex; gap: 0.75rem; align-items: center; }

.presets { border: 0; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: 0.5rem; }
.presets legend { font-size: 0.8rem; color: var(--color-suave); padding: 0 0 0.35rem; }
.preset {
    display: grid;
    justify-items: center;
    gap: 0.25rem;
    padding: 0.6rem 0.5rem;
    font: inherit;
    cursor: pointer;
    background: var(--color-fondo);
    color: var(--color-contenido);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.preset:hover { border-color: var(--color-acento); }
.preset--activo { border-color: var(--color-acento); box-shadow: 0 0 0 1px var(--color-acento); }
.preset__figura { display: block; background: color-mix(in srgb, var(--color-acento) 22%, transparent); border: 1.5px solid var(--color-acento); }
.preset__figura--rectangle { width: 2.4rem; height: 1.5rem; border-radius: 0.2rem; }
.preset__figura--circle { width: 1.8rem; height: 1.8rem; border-radius: 50%; }
.preset__nombre { font-weight: 600; font-size: 0.85rem; }
.preset__medida { font-size: 0.72rem; color: var(--color-suave); font-variant-numeric: tabular-nums; }

.conflicto { border: 2px solid var(--color-aviso); border-radius: 0.6rem; padding: 0.9rem 1.1rem; background: color-mix(in srgb, var(--color-aviso) 8%, var(--color-superficie)); }
.conflicto h2 { margin-top: 0; }
.conflicto__acciones { display: flex; gap: 0.75rem; }

.mesa-actual { margin: 0; font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
.etiqueta-baja { font-size: 0.72rem; color: var(--color-peligro); border: 1px solid currentColor; border-radius: 0.35rem; padding: 0 0.3rem; }

.nota { color: var(--color-suave); font-size: 0.85rem; margin: 0; }
.error { color: var(--color-peligro); font-size: 0.85rem; margin: 0; }

.zona-nueva { display: flex; gap: 0.5rem; margin-top: 0.25rem; }
.zona-nueva input { flex: 1; }

/* Los campos toman el look global de app.css (referencia Acadion); aquí sólo la disposición etiqueta encima del campo. */
label { display: grid; gap: 0.25rem; font-size: 0.82rem; color: var(--color-suave); }

@media (max-width: 60rem) {
    .editor__cuerpo { grid-template-columns: minmax(0, 1fr); }
}
</style>
