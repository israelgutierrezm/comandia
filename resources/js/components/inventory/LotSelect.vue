<script setup>
import { computed } from 'vue';
import { formatCalendarDate, formatQuantity } from './inventoryFormat';

/**
 * Selector del lote de un artículo, para los formularios que mueven existencia (movimientos manuales y mermas).
 *
 * ## La opción vacía tiene significado
 *
 * No es «sin elegir»: en una salida o una merma es **automático** —la salida toma primero lo que caduca antes (FEFO,
 * D23)—, y en una entrada o un ajuste es la existencia **sin lote**. Por eso el texto lo pone quien lo usa
 * (`emptyLabel`): el mismo valor vacío dice cosas distintas según la operación.
 *
 * ## De dónde salen las opciones
 *
 * Los lotes EN SURTIDO son los de `GET /articles/{ulid}/lots`, que llegan en el orden en que van a salir. Los que están
 * FUERA de surtido —caducados o agotados— ese endpoint no los devuelve (sin filtro sólo lista los activos, y con filtro de
 * estado tampoco: el filtro se suma a la condición de activo). Así que se toman de las existencias del artículo
 * (`GET /articles/{ulid}/stock`), que traen el lote de cada saldo. Son justo los que importan aquí: los que todavía tienen
 * existencia que alguien tiene que dar de baja o ajustar, porque la salida automática ya no los toma.
 *
 * Cada opción dice cuánto hay de ese lote en el almacén elegido, para no elegir a ciegas. El número viene del servidor;
 * aquí no se suma nada.
 */
const model = defineModel({ type: String, default: '' });

const props = defineProps({
    /** Para el `<label for>` de quien lo usa. */
    id: { type: String, default: null },

    /** Los lotes activos del artículo, tal como los manda `GET /articles/{ulid}/lots` (en orden de salida). */
    lots: { type: Array, default: () => [] },

    /** Los saldos del artículo, tal como los manda `GET /articles/{ulid}/stock`. */
    stocks: { type: Array, default: () => [] },

    /** El almacén elegido en el formulario: de él se dice cuánto hay de cada lote. */
    warehouseUlid: { type: String, default: '' },

    /** Ofrecer también los caducados y agotados que tienen saldo (salidas, ajustes, mermas; no entradas). */
    includeInactive: { type: Boolean, default: false },

    /** El texto de la opción vacía: «Automático: primero lo que caduca» o «Sin lote», según la operación. */
    emptyLabel: { type: String, required: true },

    /** La unidad base del artículo, para que la existencia se lea con su unidad. */
    unit: { type: String, default: '' },

    disabled: { type: Boolean, default: false },
});

/**
 * El nombre del estado de un lote fuera de surtido.
 *
 * El saldo sólo trae el código del estado (`ArticleStockResource`), no su etiqueta, así que ésta es la única copia de
 * las dos etiquetas en el cliente. Si el recurso algún día trae `status_label`, esto sobra.
 */
const ESTADOS_FUERA_DE_SURTIDO = { expired: 'caducado', depleted: 'agotado' };

/** El saldo de un lote en el almacén elegido, o `null` si no tiene fila ahí. */
function saldoAqui(loteUlid) {
    if (!props.warehouseUlid) {
        return null;
    }

    return props.stocks.find(
        (fila) => fila.lot?.ulid === loteUlid && fila.warehouse?.ulid === props.warehouseUlid,
    )?.quantity ?? null;
}

function etiqueta(lote, estado = null) {
    const partes = [lote.code];

    partes.push(lote.expires_at ? `vence ${formatCalendarDate(lote.expires_at)}` : 'no caduca');

    if (estado) {
        partes.push(estado);
    } else if (lote.is_expired) {
        // Activo con la caducidad ya pasada: la salida automática lo toma PRIMERO. Es la señal para marcarlo caducado.
        partes.push('¡ya venció!');
    }

    if (props.warehouseUlid) {
        const saldo = saldoAqui(lote.ulid);

        partes.push(saldo === null
            ? 'sin existencia aquí'
            : [formatQuantity(saldo), props.unit, 'aquí'].filter(Boolean).join(' '));
    }

    return partes.join(' · ');
}

/** Los fuera de surtido que tienen saldo en algún almacén, uno por lote (un lote puede estar en varios almacenes). */
const fueraDeSurtido = computed(() => {
    const vistos = new Map();

    for (const fila of props.stocks) {
        const lote = fila.lot;

        if (lote && lote.status !== 'active' && !vistos.has(lote.ulid)) {
            vistos.set(lote.ulid, lote);
        }
    }

    return [...vistos.values()];
});
</script>

<template>
    <select :id="props.id" v-model="model" class="input" :disabled="props.disabled">
        <option value="">{{ props.emptyLabel }}</option>

        <optgroup v-if="props.lots.length > 0" label="En surtido, en el orden en que salen">
            <option v-for="lote in props.lots" :key="lote.ulid" :value="lote.ulid">{{ etiqueta(lote) }}</option>
        </optgroup>

        <optgroup
            v-if="props.includeInactive && fueraDeSurtido.length > 0"
            label="Fuera de surtido: la salida automática ya no los toma"
        >
            <option v-for="lote in fueraDeSurtido" :key="lote.ulid" :value="lote.ulid">
                {{ etiqueta(lote, ESTADOS_FUERA_DE_SURTIDO[lote.status] ?? lote.status) }}
            </option>
        </optgroup>
    </select>
</template>

<style scoped>
@import '../../../css/admin-page.css';

select {
    width: 100%;
}
</style>
