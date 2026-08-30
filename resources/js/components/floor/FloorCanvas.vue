<script setup>
import { computed } from 'vue';

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
});

const emit = defineEmits(['select', 'move', 'resize', 'activate']);

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

/** El texto de la mesa: el código, y en el piso también lo que hay encima. */
function etiqueta(mesa) {
    return mesa.code;
}
</script>

<template>
    <svg
        class="lienzo"
        :viewBox="viewBox"
        preserveAspectRatio="xMidYMid meet"
        role="img"
        :aria-label="`Salón de ${canvas.width} por ${canvas.height} centímetros`"
    >
        <!-- La rejilla es un metro por línea: sirve de referencia porque se puede comprobar con una cinta métrica. -->
        <g class="rejilla">
            <line v-for="x in lineas.verticales" :key="`v${x}`" :x1="x" y1="0" :x2="x" :y2="canvas.height" />
            <line v-for="y in lineas.horizontales" :key="`h${y}`" x1="0" :y1="y" :x2="canvas.width" :y2="y" />
        </g>

        <g
            v-for="mesa in tables"
            :key="mesa.ulid"
            :transform="transform(mesa)"
            :class="['mesa', { 'mesa--sel': mesa.ulid === selected, 'mesa--fija': readonly, 'mesa--baja': mesa.is_archived }]"
            @pointerdown="alPresionar($event, mesa)"
            @dblclick="emit('activate', mesa)"
        >
            <rect
                v-if="mesa.geometry.shape === 'rectangle'"
                :x="mesa.geometry.x"
                :y="mesa.geometry.y"
                :width="mesa.geometry.width"
                :height="mesa.geometry.height"
                rx="6"
                :fill="COLORES[mesa.status] ?? '#eee'"
                :stroke="BORDES[mesa.status] ?? '#999'"
                stroke-width="2"
            />

            <ellipse
                v-else
                :cx="centro(mesa).x"
                :cy="centro(mesa).y"
                :rx="Number(mesa.geometry.width) / 2"
                :ry="Number(mesa.geometry.height) / 2"
                :fill="COLORES[mesa.status] ?? '#eee'"
                :stroke="BORDES[mesa.status] ?? '#999'"
                stroke-width="2"
            />

            <text :x="centro(mesa).x" :y="centro(mesa).y" text-anchor="middle" dominant-baseline="middle">
                {{ etiqueta(mesa) }}
            </text>

            <!-- Lo de encima sólo en el piso: en el editor estorbaría al colocar. -->
            <text
                v-if="readonly && mesa.account"
                :x="centro(mesa).x"
                :y="centro(mesa).y + 22"
                text-anchor="middle"
                class="mesa__cuenta"
            >
                {{ mesa.account.items_count }} art.
            </text>

            <!-- En el editor, los lugares: es el dato que decide si una mesa rectangular hace falta más grande. -->
            <text
                v-else-if="!readonly"
                :x="centro(mesa).x"
                :y="centro(mesa).y + 22"
                text-anchor="middle"
                class="mesa__lugares"
            >
                {{ mesa.seats }} lug.
            </text>

            <text
                v-if="mesa.is_archived"
                :x="centro(mesa).x"
                :y="centro(mesa).y - 22"
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
</template>

<style scoped>
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
.mesa--sel rect, .mesa--sel ellipse { stroke-width: 4; stroke-dasharray: 6 3; }
.mesa--baja { opacity: 0.45; }
.mesa text { font-size: 20px; fill: var(--color-contenido, #333); font-family: system-ui, sans-serif; pointer-events: none; }
.mesa__cuenta { font-size: 15px; fill: var(--color-suave, #666); }
.mesa__lugares { font-size: 14px; fill: var(--color-suave, #666); }
.mesa__baja { font-size: 14px; fill: var(--color-peligro, #a11); }

/* El tirador se ve y se agarra: círculo con el acento, aro blanco para separarlo de la mesa. */
.tirador {
    fill: var(--color-acento, #06c);
    stroke: #fff;
    stroke-width: 2;
    cursor: nwse-resize;
}
.tirador:hover { fill: color-mix(in srgb, var(--color-acento, #06c) 80%, #000); }
</style>
