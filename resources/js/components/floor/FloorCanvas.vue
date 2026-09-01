<script setup>
import { computed, ref } from 'vue';

/**
 * El salón dibujado. UN solo componente para el editor y para el piso de venta (ADR-003).
 *
 * ## Por qué uno y no dos
 *
 * ADR-003 lo pide literalmente —«mismo render para editor y piso de venta»— y la razón es que dos renders divergen. El
 * editor mostraría una mesa donde el piso muestra otra, y el error sería invisible hasta que alguien se sentara en la
 * mesa equivocada. Aquí sólo cambia el modo: `readonly` para el piso, editable para el editor.
 *
 * ## Coordenadas lógicas en CENTÍMETROS, nunca píxeles
 *
 * El `viewBox` son los centímetros del salón y el SVG se estira al espacio disponible. Eso significa que el mismo plano
 * se ve igual en una tableta y en un monitor, y que arrastrar 10 unidades mueve la mesa 10 cm reales — no «10 píxeles
 * de este navegador», que en otra pantalla serían otra cosa.
 *
 * El tamaño del lienzo viene del servidor. Suponerlo aquí haría que dos clientes dibujaran el mismo plano distinto,
 * que es justo lo que la columna `canvas_width` existe para impedir.
 *
 * ## Los píxeles aparecen SÓLO al convertir el gesto del ratón
 *
 * Un arrastre llega en píxeles de pantalla y hay que traducirlo a centímetros del salón. Esa conversión vive en un solo
 * sitio —`aCentimetros`— y usa la caja real del SVG, no una escala guardada: el elemento se redimensiona con la
 * ventana, y una escala calculada al montar quedaría desfasada en cuanto alguien girara la tableta.
 */
const props = defineProps({
    /** `{ width, height, unit }` en centímetros, tal como lo manda el servidor. */
    canvas: { type: Object, required: true },

    /** Las mesas con su `geometry`, su `status` y —en el piso— su `account`. */
    tables: { type: Array, required: true },

    /** Sin edición: el piso de venta. */
    readonly: { type: Boolean, default: false },

    /** ULID de la mesa seleccionada en el editor. */
    selected: { type: String, default: null },

    /**
     * Qué dice el color de la mesa: `status` (piso de venta: libre/ocupada/precuenta) o `zone` (editor: cada zona un
     * color). Es la única divergencia deliberada entre los dos modos —la geometría es idéntica (ADR-003)—: el editor
     * enseña la distribución por zonas, el piso enseña qué pasa ahora.
     */
    colorBy: { type: String, default: 'status' },

    /** Las zonas del plano, en orden: fija el color de cada una y pinta la leyenda. */
    zones: { type: Array, default: () => [] },

    /** Muestra los controles de zoom y el lienzo desplazable (editor). */
    zoomable: { type: Boolean, default: false },

    /** Elementos decorativos del plano (ADR-011): muros, puertas, rótulos. Se dibujan DETRÁS de las mesas. */
    elements: { type: Array, default: () => [] },

    /** ULID del elemento seleccionado en el editor. */
    selectedElement: { type: String, default: null },
});

const emit = defineEmits([
    'select', 'move', 'resize', 'activate',
    'element-select', 'element-move', 'element-resize',
]);

/** Una mesa no puede achicarse hasta desaparecer: por debajo de esto el tirador ya no se podría agarrar. */
const MIN_CM = 30;

/**
 * El color dice el estado de la mesa, y es lo único que se mira desde lejos: verde = libre, rojo = ocupada,
 * amarillo = con la cuenta pedida (precuenta). Los otros dos estados conservan un tinte propio.
 */
const COLORES = {
    free: '#e8f5e9',
    occupied: '#ffebee',
    bill_requested: '#fff8e1',
    needs_cleaning: '#eceff1',
    reserved: '#f3e5f5',
};

const BORDES = {
    free: '#43a047',
    occupied: '#e53935',
    bill_requested: '#f9a825',
    needs_cleaning: '#78909c',
    reserved: '#ab47bc',
};

/**
 * Colores por estado de la CUENTA (colorBy="cuenta"), para el piso de cuentas: libre, abierta, pendiente por comandar y
 * cuenta solicitada. Es un modo distinto al del piso de venta (que colorea por estado de MESA) porque aquí lo que
 * importa es en qué punto va la cuenta, no si la mesa está limpia. Editor y Piso no lo usan.
 */
const ESTADO_CUENTA = {
    libre: { fill: '#e8f5e9', stroke: '#43a047' },
    abierta: { fill: '#e3f0ff', stroke: '#3b82c4' },
    pendiente: { fill: '#ffe6d6', stroke: '#e8703a' },
    solicitada: { fill: '#fff3cd', stroke: '#d9a441' },
};

/** El estado de la cuenta de una mesa, para el color y la leyenda del piso de cuentas. */
function estadoCuenta(mesa) {
    if (! mesa.account) {
        return 'libre';
    }

    if (mesa.account.bill_requested_at) {
        return 'solicitada';
    }

    if (Number(mesa.account.pending_to_command ?? 0) > 0) {
        return 'pendiente';
    }

    return 'abierta';
}

/**
 * Paleta de zonas SOBRIA: los rellenos son los fondos de los `alert` de Bootstrap (claros y apagados) y el borde un tono
 * medio del mismo color para que la mesa se lea sin gritar. Se asigna por ORDEN de la zona, así el mismo salón se
 * colorea igual siempre.
 */
const PALETA_ZONAS = [
    { fill: '#fff3cd', stroke: '#d9a441' },
    { fill: '#f8d7da', stroke: '#cf7f88' },
    { fill: '#d1e7dd', stroke: '#74b596' },
    { fill: '#cfe2ff', stroke: '#86b0ea' },
    { fill: '#e7ddf5', stroke: '#a98ed6' },
    { fill: '#cff4fc', stroke: '#77cfe0' },
];

/** Zona ULID → índice de color. Del orden de `zones` si viene; si no, del orden de aparición en las mesas. */
const zonaIndice = computed(() => {
    const orden = props.zones.length
        ? props.zones.map((z) => z.ulid)
        : [...new Set(props.tables.map((m) => m.zone?.ulid).filter(Boolean))];

    const mapa = {};
    orden.forEach((ulid, i) => { mapa[ulid] = i % PALETA_ZONAS.length; });

    return mapa;
});

/** El color de una mesa según el modo: por zona (editor) o por estado (piso). */
function colorDe(mesa) {
    if (props.colorBy === 'zone') {
        return PALETA_ZONAS[zonaIndice.value[mesa.zone?.ulid]] ?? { fill: '#eee', stroke: '#999' };
    }

    if (props.colorBy === 'cuenta') {
        return ESTADO_CUENTA[estadoCuenta(mesa)];
    }

    return { fill: COLORES[mesa.status] ?? '#eee', stroke: BORDES[mesa.status] ?? '#999' };
}

/**
 * Las «sillas»: cuatro tabs a los lados de la mesa. Son decorativas —dan el aire de mesa con lugares del mockup— y se
 * dibujan detrás de la mesa para que asomen por el borde. En cm, sobre la caja de la mesa.
 */
function sillas(mesa) {
    const x = Number(mesa.geometry.x);
    const y = Number(mesa.geometry.y);
    const w = Number(mesa.geometry.width);
    const h = Number(mesa.geometry.height);
    const largo = 20;
    const grueso = 9;

    return [
        { x: x + w / 2 - largo / 2, y: y - grueso / 2, w: largo, h: grueso },
        { x: x + w / 2 - largo / 2, y: y + h - grueso / 2, w: largo, h: grueso },
        { x: x - grueso / 2, y: y + h / 2 - largo / 2, w: grueso, h: largo },
        { x: x + w - grueso / 2, y: y + h / 2 - largo / 2, w: grueso, h: largo },
    ];
}

// ---------------------------------------------------------------- Zoom (editor)

const zoom = ref(1);
const ZOOM_MIN = 0.4;
const ZOOM_MAX = 2.5;
const ZOOM_STEP = 0.2;

const zoomPct = computed(() => Math.round(zoom.value * 100));

function acercar() { zoom.value = Math.min(ZOOM_MAX, Math.round((zoom.value + ZOOM_STEP) * 100) / 100); }
function alejar() { zoom.value = Math.max(ZOOM_MIN, Math.round((zoom.value - ZOOM_STEP) * 100) / 100); }
function ajustarZoom() { zoom.value = 1; }

const viewBox = computed(() => `0 0 ${props.canvas.width} ${props.canvas.height}`);

/**
 * La rejilla, en metros.
 *
 * Cada línea es un metro real, y por eso sirve de referencia al colocar: «esta mesa está a dos metros de la pared» es
 * una frase que alguien puede comprobar con una cinta métrica. Una rejilla en unidades arbitrarias sería decoración.
 */
const lineas = computed(() => {
    const w = Number(props.canvas.width);
    const h = Number(props.canvas.height);
    const paso = 100;

    const verticales = [];
    const horizontales = [];

    for (let x = paso; x < w; x += paso) verticales.push(x);
    for (let y = paso; y < h; y += paso) horizontales.push(y);

    return { verticales, horizontales };
});

/** El centro de una mesa, que es donde va su etiqueta y el eje sobre el que rota. */
function centro(mesa) {
    return {
        x: Number(mesa.geometry.x) + Number(mesa.geometry.width) / 2,
        y: Number(mesa.geometry.y) + Number(mesa.geometry.height) / 2,
    };
}

function transform(mesa) {
    const c = centro(mesa);

    return `rotate(${mesa.geometry.rotation} ${c.x} ${c.y})`;
}

// ---------------------------------------------------------------- Arrastre

let arrastrando = null;

/**
 * Píxeles de pantalla a centímetros del salón.
 *
 * Es la ÚNICA conversión del componente. Se calcula con la caja real del SVG en el momento del gesto porque el
 * elemento se estira con la ventana: una escala guardada al montar quedaría desfasada en cuanto alguien girara la
 * tableta, y las mesas empezarían a moverse más o menos de lo que el dedo indica.
 */
function aCentimetros(evento, svg) {
    const caja = svg.getBoundingClientRect();

    return {
        x: ((evento.clientX - caja.left) / caja.width) * Number(props.canvas.width),
        y: ((evento.clientY - caja.top) / caja.height) * Number(props.canvas.height),
    };
}

function alPresionar(evento, mesa) {
    emit('select', mesa.ulid);

    if (props.readonly) {
        return;
    }

    const svg = evento.currentTarget.ownerSVGElement;
    const punto = aCentimetros(evento, svg);

    // Se guarda el DESFASE entre el puntero y la esquina de la mesa. Sin él, la mesa saltaría para centrarse bajo el
    // cursor en cuanto empieza el arrastre, y colocarla con precisión sería imposible.
    arrastrando = {
        ulid: mesa.ulid,
        dx: punto.x - Number(mesa.geometry.x),
        dy: punto.y - Number(mesa.geometry.y),
        svg,
    };

    window.addEventListener('pointermove', alMover);
    window.addEventListener('pointerup', alSoltar);
}

function alMover(evento) {
    if (! arrastrando) {
        return;
    }

    const punto = aCentimetros(evento, arrastrando.svg);

    // Se recorta al lienzo: una mesa fuera del salón no se puede volver a agarrar, y el servidor la rechazaría con un
    // 422 después de que quien la arrastró ya la perdió de vista.
    const mesa = props.tables.find((m) => m.ulid === arrastrando.ulid);

    if (! mesa) {
        return;
    }

    const x = recortar(punto.x - arrastrando.dx, Number(props.canvas.width) - Number(mesa.geometry.width));
    const y = recortar(punto.y - arrastrando.dy, Number(props.canvas.height) - Number(mesa.geometry.height));

    emit('move', { ulid: arrastrando.ulid, x, y });
}

function alSoltar() {
    arrastrando = null;

    window.removeEventListener('pointermove', alMover);
    window.removeEventListener('pointerup', alSoltar);
}

function recortar(valor, maximo) {
    return Math.round(Math.min(Math.max(valor, 0), Math.max(maximo, 0)) * 100) / 100;
}

// ---------------------------------------------------------------- Redimensionar

let redimensionando = null;

/**
 * Rotar un punto alrededor de un pivote. El tirador vive dentro del grupo rotado de la mesa, así que el gesto del ratón
 * —que llega en coordenadas del lienzo— hay que DES-rotarlo para saber cuánto midió el arrastre en el eje propio de la
 * mesa. Sin esto, redimensionar una mesa girada crecería en diagonal.
 */
function rotar(px, py, cx, cy, grados) {
    const r = (grados * Math.PI) / 180;
    const cos = Math.cos(r);
    const sin = Math.sin(r);
    const dx = px - cx;
    const dy = py - cy;

    return { x: cx + dx * cos - dy * sin, y: cy + dx * sin + dy * cos };
}

function alPresionarTirador(evento, mesa) {
    // El tirador no debe iniciar también el arrastre de la mesa: son dos gestos distintos sobre el mismo grupo.
    evento.stopPropagation();

    if (props.readonly) {
        return;
    }

    const c = centro(mesa);

    // El pivote de rotación se congela al inicio del gesto: recalcularlo con el centro cambiante haría que la mesa
    // «resbalara» mientras se redimensiona.
    redimensionando = {
        ulid: mesa.ulid,
        svg: evento.currentTarget.ownerSVGElement,
        cx: c.x,
        cy: c.y,
        x: Number(mesa.geometry.x),
        y: Number(mesa.geometry.y),
        rotation: Number(mesa.geometry.rotation),
    };

    window.addEventListener('pointermove', alRedimensionar);
    window.addEventListener('pointerup', alSoltarTirador);
}

function alRedimensionar(evento) {
    if (! redimensionando) {
        return;
    }

    const p = aCentimetros(evento, redimensionando.svg);
    const local = rotar(p.x, p.y, redimensionando.cx, redimensionando.cy, -redimensionando.rotation);

    // La esquina superior izquierda queda fija: el tirador es el inferior derecho, así que ancho y alto son la distancia
    // del puntero a esa esquina. Se recorta al lienzo y a un mínimo agarrable.
    const width = Math.max(MIN_CM, recortar(local.x - redimensionando.x, Number(props.canvas.width) - redimensionando.x));
    const height = Math.max(MIN_CM, recortar(local.y - redimensionando.y, Number(props.canvas.height) - redimensionando.y));

    emit('resize', { ulid: redimensionando.ulid, width, height });
}

function alSoltarTirador() {
    redimensionando = null;

    window.removeEventListener('pointermove', alRedimensionar);
    window.removeEventListener('pointerup', alSoltarTirador);
}

// ---------------------------------------------------------------- Elementos (muros, puertas, rótulos)

let arrastrandoEl = null;

function alPresionarElemento(evento, el) {
    emit('element-select', el.ulid);

    if (props.readonly) {
        return;
    }

    const svg = evento.currentTarget.ownerSVGElement;
    const punto = aCentimetros(evento, svg);

    arrastrandoEl = {
        ulid: el.ulid,
        dx: punto.x - Number(el.geometry.x),
        dy: punto.y - Number(el.geometry.y),
        svg,
    };

    window.addEventListener('pointermove', alMoverElemento);
    window.addEventListener('pointerup', alSoltarElemento);
}

function alMoverElemento(evento) {
    if (! arrastrandoEl) {
        return;
    }

    const el = props.elements.find((e) => e.ulid === arrastrandoEl.ulid);

    if (! el) {
        return;
    }

    const punto = aCentimetros(evento, arrastrandoEl.svg);
    const x = recortar(punto.x - arrastrandoEl.dx, Number(props.canvas.width) - Number(el.geometry.width));
    const y = recortar(punto.y - arrastrandoEl.dy, Number(props.canvas.height) - Number(el.geometry.height));

    emit('element-move', { ulid: arrastrandoEl.ulid, x, y });
}

function alSoltarElemento() {
    arrastrandoEl = null;

    window.removeEventListener('pointermove', alMoverElemento);
    window.removeEventListener('pointerup', alSoltarElemento);
}

let redimensionandoEl = null;

function alPresionarTiradorElemento(evento, el) {
    evento.stopPropagation();

    if (props.readonly) {
        return;
    }

    const c = centro(el);

    redimensionandoEl = {
        ulid: el.ulid,
        svg: evento.currentTarget.ownerSVGElement,
        cx: c.x,
        cy: c.y,
        x: Number(el.geometry.x),
        y: Number(el.geometry.y),
        rotation: Number(el.geometry.rotation),
    };

    window.addEventListener('pointermove', alRedimensionarElemento);
    window.addEventListener('pointerup', alSoltarTiradorElemento);
}

function alRedimensionarElemento(evento) {
    if (! redimensionandoEl) {
        return;
    }

    const p = aCentimetros(evento, redimensionandoEl.svg);
    const local = rotar(p.x, p.y, redimensionandoEl.cx, redimensionandoEl.cy, -redimensionandoEl.rotation);

    const width = Math.max(MIN_CM, recortar(local.x - redimensionandoEl.x, Number(props.canvas.width) - redimensionandoEl.x));
    const height = Math.max(MIN_CM, recortar(local.y - redimensionandoEl.y, Number(props.canvas.height) - redimensionandoEl.y));

    emit('element-resize', { ulid: redimensionandoEl.ulid, width, height });
}

function alSoltarTiradorElemento() {
    redimensionandoEl = null;

    window.removeEventListener('pointermove', alRedimensionarElemento);
    window.removeEventListener('pointerup', alSoltarTiradorElemento);
}

/** El trazo de una puerta: el hoja (línea vertical del gozne) y el arco del giro, dentro de su caja. */
function arcoPuerta(el) {
    const x = Number(el.geometry.x);
    const y = Number(el.geometry.y);
    const w = Number(el.geometry.width);
    const h = Number(el.geometry.height);

    return `M ${x} ${y + h} L ${x} ${y} A ${w} ${h} 0 0 1 ${x + w} ${y + h}`;
}

/** Tamaño de letra de un rótulo, proporcional a su alto y con tope. */
function rotuloTam(el) {
    return Math.min(Number(el.geometry.height) * 0.62, 40);
}

/** El texto de la mesa: el código, y en el piso también lo que hay encima. */
function etiqueta(mesa) {
    return mesa.code;
}
</script>

<template>
    <div class="canvas-wrap">
        <!-- Zoom (editor). El arrastre sigue exacto: la conversión usa la caja real del SVG, que ya viene escalada. -->
        <div v-if="zoomable" class="zoom-controls">
            <button type="button" aria-label="Acercar" @click="acercar">+</button>
            <span class="zoom-controls__pct">{{ zoomPct }}%</span>
            <button type="button" aria-label="Alejar" @click="alejar">−</button>
            <button type="button" class="zoom-controls__fit" title="Ajustar" aria-label="Ajustar" @click="ajustarZoom">⤢</button>
        </div>

        <div :class="{ viewport: zoomable }">
            <svg
                class="lienzo"
                :viewBox="viewBox"
                preserveAspectRatio="xMidYMid meet"
                role="img"
                :aria-label="`Salón de ${canvas.width} por ${canvas.height} centímetros`"
                :style="zoomable ? { width: `${zoomPct}%` } : undefined"
            >
                <!-- La rejilla es un metro por línea: sirve de referencia porque se puede comprobar con una cinta métrica. -->
                <g class="rejilla">
                    <line v-for="x in lineas.verticales" :key="`v${x}`" :x1="x" y1="0" :x2="x" :y2="canvas.height" />
                    <line v-for="y in lineas.horizontales" :key="`h${y}`" x1="0" :y1="y" :x2="canvas.width" :y2="y" />
                </g>

                <!-- Elementos decorativos DETRÁS de las mesas (ADR-011): muros, puertas, rótulos. -->
                <g
                    v-for="el in elements"
                    :key="el.ulid"
                    :transform="transform(el)"
                    :class="['elemento', `elemento--${el.kind}`, { 'elemento--sel': el.ulid === selectedElement, 'elemento--fija': readonly }]"
                    @pointerdown="alPresionarElemento($event, el)"
                >
                    <rect
                        v-if="el.kind === 'wall'"
                        class="muro"
                        :x="el.geometry.x"
                        :y="el.geometry.y"
                        :width="el.geometry.width"
                        :height="el.geometry.height"
                        rx="3"
                    />

                    <template v-else-if="el.kind === 'door'">
                        <rect
                            class="puerta"
                            :x="el.geometry.x"
                            :y="el.geometry.y"
                            :width="el.geometry.width"
                            :height="el.geometry.height"
                            rx="2"
                        />
                        <path class="puerta__arco" :d="arcoPuerta(el)" />
                    </template>

                    <text
                        v-else
                        class="rotulo"
                        :x="centro(el).x"
                        :y="centro(el).y"
                        text-anchor="middle"
                        dominant-baseline="middle"
                        :style="{ fontSize: `${rotuloTam(el)}px` }"
                    >
                        {{ el.text }}
                    </text>

                    <circle
                        v-if="!readonly && el.ulid === selectedElement"
                        class="tirador"
                        :cx="Number(el.geometry.x) + Number(el.geometry.width)"
                        :cy="Number(el.geometry.y) + Number(el.geometry.height)"
                        r="11"
                        @pointerdown="alPresionarTiradorElemento($event, el)"
                    />
                </g>

                <g
                    v-for="mesa in tables"
                    :key="mesa.ulid"
                    :transform="transform(mesa)"
                    :class="['mesa', { 'mesa--sel': mesa.ulid === selected, 'mesa--fija': readonly, 'mesa--baja': mesa.is_archived }]"
                    @pointerdown="alPresionar($event, mesa)"
                    @dblclick="emit('activate', mesa)"
                >
                    <!-- Sillas: detrás de la mesa para que asomen por el borde. -->
                    <rect
                        v-for="(silla, i) in sillas(mesa)"
                        :key="`s${i}`"
                        class="silla"
                        :x="silla.x"
                        :y="silla.y"
                        :width="silla.w"
                        :height="silla.h"
                        rx="3"
                        :fill="colorDe(mesa).stroke"
                    />

                    <rect
                        v-if="mesa.geometry.shape === 'rectangle'"
                        :x="mesa.geometry.x"
                        :y="mesa.geometry.y"
                        :width="mesa.geometry.width"
                        :height="mesa.geometry.height"
                        rx="8"
                        :fill="colorDe(mesa).fill"
                        :stroke="colorDe(mesa).stroke"
                        stroke-width="2.5"
                    />

                    <ellipse
                        v-else
                        :cx="centro(mesa).x"
                        :cy="centro(mesa).y"
                        :rx="Number(mesa.geometry.width) / 2"
                        :ry="Number(mesa.geometry.height) / 2"
                        :fill="colorDe(mesa).fill"
                        :stroke="colorDe(mesa).stroke"
                        stroke-width="2.5"
                    />

                    <text
                        class="mesa__code"
                        :x="centro(mesa).x"
                        :y="centro(mesa).y - 6"
                        text-anchor="middle"
                        dominant-baseline="middle"
                    >
                        {{ etiqueta(mesa) }}
                    </text>

                    <!-- En el piso, lo que hay encima; en el editor, la capacidad con un icono de persona. -->
                    <text
                        v-if="readonly && mesa.account"
                        :x="centro(mesa).x"
                        :y="centro(mesa).y + 18"
                        text-anchor="middle"
                        class="mesa__cuenta"
                    >
                        <!-- El piso de cuentas pasa `total_label` (importe ya formateado); el piso de venta no, y ahí se
                             sigue viendo cuántos artículos lleva la mesa. -->
                        {{ mesa.account.total_label ?? `${mesa.account.items_count} art.` }}
                    </text>

                    <g v-else-if="!readonly" :transform="`translate(${centro(mesa).x} ${centro(mesa).y + 15})`">
                        <circle class="mesa__cap-ic" cx="-14" cy="-5" r="4.5" />
                        <path class="mesa__cap-ic" d="M-21 4 a6.5 6.5 0 0 1 13 0 z" />
                        <text class="mesa__cap-n" x="-4" y="1" text-anchor="start" dominant-baseline="middle">{{ mesa.seats }}</text>
                    </g>

                    <text
                        v-if="mesa.is_archived"
                        :x="centro(mesa).x"
                        :y="centro(mesa).y - 24"
                        text-anchor="middle"
                        class="mesa__baja"
                    >
                        retirada
                    </text>

                    <!-- El tirador de redimensionar: sólo en la mesa seleccionada del editor. Arrastrarlo cambia ancho y alto. -->
                    <circle
                        v-if="!readonly && mesa.ulid === selected"
                        class="tirador"
                        :cx="Number(mesa.geometry.x) + Number(mesa.geometry.width)"
                        :cy="Number(mesa.geometry.y) + Number(mesa.geometry.height)"
                        r="11"
                        @pointerdown="alPresionarTirador($event, mesa)"
                    />
                </g>
            </svg>
        </div>

    </div>
</template>

<style scoped>
.canvas-wrap { position: relative; }

/* Lienzo desplazable cuando hay zoom: el SVG crece por encima del 100% y el contenedor hace scroll. */
.viewport { overflow: auto; max-height: 72vh; border-radius: 0.5rem; }
.viewport .lienzo { border-radius: 0; }

.lienzo {
    width: 100%;
    height: auto;
    background: var(--color-fondo, #fcfcfa);
    border: 1px solid var(--color-borde, #d6d6d6);
    border-radius: 0.5rem;
    touch-action: none;
}
.rejilla line { stroke: color-mix(in srgb, var(--color-borde, #ececec) 55%, transparent); stroke-width: 1; }
.mesa { cursor: grab; }
.mesa--fija { cursor: pointer; }
.mesa--sel rect, .mesa--sel ellipse { stroke-width: 4.5; }
.mesa--baja { opacity: 0.45; }
.mesa text { font-family: system-ui, sans-serif; pointer-events: none; }
.mesa__code { font-size: 22px; font-weight: 600; fill: var(--color-contenido, #333); }
.mesa__cuenta { font-size: 15px; fill: var(--color-suave, #666); }
.mesa__cap-ic { fill: var(--color-suave, #666); }
.mesa__cap-n { font-size: 17px; font-weight: 600; fill: var(--color-suave, #666); }
.mesa__baja { font-size: 14px; fill: var(--color-peligro, #a11); }
.silla { opacity: 0.85; }

/* El tirador se ve y se agarra: círculo con el acento, aro blanco para separarlo de la mesa. */
.tirador {
    fill: var(--color-acento, #06c);
    stroke: #fff;
    stroke-width: 2;
    cursor: nwse-resize;
}
.tirador:hover { fill: color-mix(in srgb, var(--color-acento, #06c) 80%, #000); }

/* Elementos decorativos (ADR-011): sobrios y detrás de las mesas. En el piso (readonly) no capturan el puntero. */
.elemento { cursor: grab; }
.elemento--fija { cursor: default; pointer-events: none; }
.elemento--sel .muro, .elemento--sel .puerta { stroke-width: 4; }
.muro { fill: #d4d7dc; stroke: #9aa0a6; stroke-width: 2; }
.puerta { fill: none; stroke: #9aa0a6; stroke-width: 2; stroke-dasharray: 7 5; }
.puerta__arco { fill: none; stroke: #9aa0a6; stroke-width: 1.5; }
.rotulo { fill: var(--color-suave, #666); font-weight: 600; font-family: system-ui, sans-serif; }

/* Controles de zoom, apilados arriba a la izquierda del lienzo (referencia del mockup). */
.zoom-controls {
    position: absolute;
    top: 0.6rem;
    left: 0.6rem;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.6rem;
    box-shadow: 0 2px 6px rgb(0 0 0 / 0.08);
    overflow: hidden;
}
.zoom-controls button {
    border: 0;
    background: transparent;
    color: var(--color-contenido);
    cursor: pointer;
    width: 2.2rem;
    height: 2rem;
    font-size: 1.05rem;
    display: grid;
    place-items: center;
    transition: background-color 0.15s ease, color 0.15s ease;
}
.zoom-controls button:hover { background: color-mix(in srgb, var(--color-acento) 10%, transparent); color: var(--color-acento); }
.zoom-controls__pct { font-size: 0.7rem; text-align: center; color: var(--color-suave); padding: 0.15rem 0; font-variant-numeric: tabular-nums; border-block: 1px solid var(--color-borde); }
.zoom-controls__fit { border-top: 1px solid var(--color-borde); font-size: 0.95rem; }
</style>
