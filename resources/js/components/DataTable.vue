<script setup>
import { computed, ref } from 'vue';

/**
 * Tabla de listado con estados de carga, vacío y error.
 *
 * Los tres estados están aquí y no en cada pantalla porque son los que más se olvidan: una tabla que
 * no distingue "cargando" de "no hay nada" hace que el usuario crea que no tiene datos, y una que no
 * muestra el error deja un listado vacío donde en realidad hubo un 403.
 *
 * ## Reordenar arrastrando (`reorderable`)
 *
 * Para las entidades con un orden propio (`sort_order`): aparece un tirador y las filas se jalan para
 * reacomodarlas. Al soltar, la tabla emite `reorder` con el arreglo YA reordenado; persistirlo —y
 * renumerar— es responsabilidad de la pantalla, que es la única que sabe por qué endpoint. El tirador
 * es el único elemento arrastrable, para no estorbar a los botones y enlaces de la fila.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    error: { type: Object, default: null },
    emptyMessage: { type: String, default: 'No hay registros.' },
    reorderable: { type: Boolean, default: false },
});

const emit = defineEmits(['reorder']);

/** El total de columnas para el `colspan` de los estados, contando la del tirador. */
const colspanTotal = computed(() => props.columns.length + (props.reorderable ? 1 : 0));

const dragFrom = ref(null);
const dragOver = ref(null);

function onDragStart(index) {
    dragFrom.value = index;
}

function onDragOver(index) {
    dragOver.value = index;
}

function onDrop(index) {
    const from = dragFrom.value;

    if (from === null || from === index) {
        reset();
        return;
    }

    const next = [...props.rows];
    const [moved] = next.splice(from, 1);
    next.splice(index, 0, moved);

    emit('reorder', next);
    reset();
}

function reset() {
    dragFrom.value = null;
    dragOver.value = null;
}

/**
 * Alineación opcional por columna (`align: 'right' | 'center'`). Las columnas de dinero y cantidades se alinean a la
 * derecha con cifras tabulares, que es como se leen y comparan; el resto sigue a la izquierda.
 */
function alignClass(column) {
    if (column.align === 'right') return 'col--right';
    if (column.align === 'center') return 'col--center';
    return '';
}
</script>

<template>
    <div class="wrapper">
        <div v-if="error" class="state state--error">
            <p class="state__title">
                {{ error.isForbidden ? 'No tienes permiso para ver esta información.' : error.message }}
            </p>
            <p v-if="error.isForbidden" class="state__hint">
                Si crees que deberías tenerlo, pide que revisen tu rol.
            </p>
        </div>

        <table v-else class="table">
            <thead>
                <tr>
                    <th v-if="reorderable" class="th-handle" aria-label="Orden"></th>
                    <th v-for="column in columns" :key="column.key" :class="alignClass(column)" :style="column.width ? `width:${column.width}` : ''">
                        {{ column.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <!-- Sin fila «Cargando…»: la barra superior es el indicador de carga. El vacío sólo cuando NO carga. -->
                <tr v-if="!loading && rows.length === 0">
                    <td :colspan="colspanTotal" class="state state--empty">
                        <svg class="state__icon" viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7l1.5-2.5h13L20 7M4 7v11a1.5 1.5 0 001.5 1.5h13A1.5 1.5 0 0020 18V7M4 7h16M9 11.5h6" />
                        </svg>
                        <span>{{ emptyMessage }}</span>
                    </td>
                </tr>

                <tr
                    v-for="(row, index) in loading ? [] : rows"
                    :key="row.ulid ?? index"
                    :class="{ 'row--dragover': reorderable && dragOver === index && dragFrom !== index }"
                    @dragover.prevent="reorderable && onDragOver(index)"
                    @drop="reorderable && onDrop(index)"
                    @dragend="reset"
                >
                    <td v-if="reorderable" class="td-handle">
                        <span
                            class="handle"
                            draggable="true"
                            title="Arrastra para reordenar"
                            aria-label="Arrastra para reordenar"
                            @dragstart="onDragStart(index)"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
                                <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
                                <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
                            </svg>
                        </span>
                    </td>
                    <td v-for="column in columns" :key="column.key">
                        <slot :name="`cell:${column.key}`" :row="row">
                            {{ row[column.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.wrapper {
    background: var(--color-superficie, #fff);
    border: 1px solid var(--color-borde, #e7e5e4);
    border-radius: var(--radio, 0.6rem);
    box-shadow: var(--sombra-sm, 0 1px 2px rgb(0 0 0 / 0.04), 0 1px 3px rgb(0 0 0 / 0.06));
    /* Las tablas anchas se desplazan dentro de su contenedor: el cuerpo de la página nunca. */
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

th,
td {
    padding: 0.6rem 0.85rem;
    text-align: left;
    border-bottom: 1px solid var(--color-borde, #f5f5f4);
    white-space: nowrap;
}

th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-suave, #78716c);
    background: color-mix(in srgb, var(--color-suave, #78716c) 6%, var(--color-superficie, #fafaf9));
}

tbody tr:last-child td {
    border-bottom: 0;
}

/* Hover de fila: una pista de que la fila es una unidad y de que se puede actuar sobre ella. No tiñe la fila de estado
   vacío (su celda lleva `.state`). */
tbody tr { transition: background-color 0.12s ease; }
tbody tr:hover td:not(.state) {
    background: color-mix(in srgb, var(--color-acento) 5%, transparent);
}

/* Alineación por columna. La derecha, con cifras tabulares, es para dinero y cantidades. */
th.col--right, td.col--right { text-align: right; font-variant-numeric: tabular-nums; }
th.col--center, td.col--center { text-align: center; }

.state {
    padding: 1.5rem;
    text-align: center;
    color: var(--color-suave, #78716c);
}

.state--empty { padding: 2.75rem 1.5rem; }
.state__icon { display: block; margin: 0 auto 0.6rem; color: var(--color-suave, #78716c); opacity: 0.5; }

.state--error {
    text-align: left;
    color: var(--color-peligro, #b91c1c);
}

.state__title {
    margin: 0;
    font-weight: 500;
}

.state__hint {
    margin: 0.25rem 0 0;
    font-size: 0.85rem;
    color: var(--color-suave, #78716c);
}

/* Reordenar arrastrando. */
.th-handle, .td-handle { width: 2.2rem; padding-right: 0; }
.handle {
    display: inline-grid;
    place-items: center;
    color: var(--color-suave);
    cursor: grab;
    border-radius: 0.35rem;
    padding: 0.15rem;
}
.handle:hover { color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.handle:active { cursor: grabbing; }
.row--dragover td { box-shadow: inset 0 2px 0 var(--color-acento); }
</style>
