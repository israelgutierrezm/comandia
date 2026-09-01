import { ref } from 'vue';

/**
 * Estado y cálculo de un arrastre para reordenar, reutilizable en cualquier lista (tablas anidadas, árboles, zonas…).
 *
 * No persiste ni conoce el endpoint: sólo lleva de qué fila se partió (`from`), sobre cuál se está (`over`, para pintar
 * el indicador) y, al soltar, DEVUELVE el arreglo ya reordenado —o `null` si no cambió nada— para que la pantalla lo
 * persista como corresponda. El único elemento arrastrable debe ser el tirador, para no estorbar a los controles de la
 * fila; esta utilidad no lo impone, lo hace el `draggable` en la plantilla.
 */
export function useReorder() {
    const from = ref(null);
    const over = ref(null);

    function start(index) {
        from.value = index;
    }

    function enter(index) {
        if (from.value !== null) {
            over.value = index;
        }
    }

    function end() {
        from.value = null;
        over.value = null;
    }

    /**
     * Suelta sobre `index`: devuelve una copia de `items` con el elemento movido, o `null` si no hubo cambio.
     *
     * @param {number} index
     * @param {Array} items
     * @returns {Array|null}
     */
    function reorder(index, items) {
        const desde = from.value;
        end();

        if (desde === null || desde === index) {
            return null;
        }

        const next = [...items];
        const [movido] = next.splice(desde, 1);
        next.splice(index, 0, movido);

        return next;
    }

    return { from, over, start, enter, end, reorder };
}
