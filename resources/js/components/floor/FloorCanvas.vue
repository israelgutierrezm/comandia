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

const emit = defineEmits(['select', 'move', 'activate']);

/** El color dice el estado de la mesa, y es lo único que se mira desde lejos. */
const COLORES = {
    free: '#e8f5e9',
    occupied: '#fff3e0',
    bill_requested: '#e3f2fd',
    needs_cleaning: '#fbe9e7',
    reserved: '#f3e5f5',
};

const BORDES = {
    free: '#66bb6a',
    occupied: '#ffa726',
    bill_requested: '#42a5f5',
    needs_cleaning: '#ff7043',
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

            <text
                v-if="mesa.is_archived"
                :x="centro(mesa).x"
                :y="centro(mesa).y - 22"
                text-anchor="middle"
                class="mesa__baja"
            >
                retirada
            </text>
        </g>
    </svg>
</template>

<style scoped>
.lienzo { width: 100%; height: auto; background: #fcfcfa; border: 1px solid #d6d6d6; border-radius: 6px; touch-action: none; }
.rejilla line { stroke: #ececec; stroke-width: 1; }
.mesa { cursor: grab; }
.mesa--fija { cursor: pointer; }
.mesa--sel rect, .mesa--sel ellipse { stroke-width: 4; stroke-dasharray: 6 3; }
.mesa--baja { opacity: 0.45; }
.mesa text { font-size: 20px; fill: #333; font-family: system-ui, sans-serif; pointer-events: none; }
.mesa__cuenta { font-size: 15px; fill: #666; }
.mesa__baja { font-size: 14px; fill: #a11; }
</style>
