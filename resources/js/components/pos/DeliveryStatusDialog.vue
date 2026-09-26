<script setup>
import { computed, ref } from 'vue';
import { api, ApiError } from '../../api/client';
import { formatMoney as money } from '../../support/money';
import Icon from '../Icon.vue';
import PosDialog from './PosDialog.vue';

/**
 * Avanzar la entrega de un pedido para llevar (§6.3): pendiente → listo → entregado.
 *
 * `POST /pos-accounts/{cuenta}/delivery` con `delivery_status` (`ready` o `delivered`). Los pasos posibles NO se deducen
 * aquí: los publica el servidor en `delivery_allowed_next` (de «Pendiente» se puede saltar directo a «Entregado»), junto
 * con el estado actual y su etiqueta. El avance sólo va hacia adelante —entregar es un hecho físico— y no depende del
 * cobro (D269): por eso, si la cuenta todavía debe algo, la ventana lo recuerda sin impedirlo. Devuelve la cuenta.
 *
 * Tocar un paso es la confirmación: la ventana ya dice qué significa cada uno y que no hay vuelta atrás.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

// Cómo se lee cada paso en el mostrador. Sólo presentación: cuáles se ofrecen lo decide el servidor.
const PASOS = {
    ready: { titulo: 'Listo para entregar', detalle: 'Ya está en el mostrador: es cuando se llama al cliente.', icono: 'check' },
    delivered: { titulo: 'Entregado', detalle: 'El cliente ya se lo llevó.', icono: 'truck' },
};

const TONO_ACTUAL = { pending: 'badge--warn', ready: 'badge--ok', delivered: 'badge--off' };

const enCurso = ref(null); // el paso que se está guardando
const error = ref(null);

const pasos = computed(() => (props.account.delivery_allowed_next ?? []).map((valor) => ({
    valor,
    titulo: PASOS[valor]?.titulo ?? valor,
    detalle: PASOS[valor]?.detalle ?? '',
    icono: PASOS[valor]?.icono ?? 'check',
})));

const debe = computed(() => (props.account.totals?.due ?? '0.00') !== '0.00');

async function avanzar(valor) {
    if (enCurso.value) {
        return;
    }

    enCurso.value = valor;
    error.value = null;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/delivery`, {
            // El servidor no revisa la versión aquí; viaja igual, como en toda escritura de la cuenta.
            version,
            delivery_status: valor,
        }));

        emit('hecho', { cuenta: data, aviso: `${data.display_name}: ${data.delivery_status_label}.` });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;

        // Un 409 es que el estado ya cambió en otra pantalla: se vuelve a leer para ofrecer los pasos al día.
        if (e.status === 409) {
            emit('refrescar');
        }
    } finally {
        enCurso.value = null;
    }
}
</script>

<template>
    <PosDialog titulo="Entrega del pedido" ancho="chico" :bloqueada="enCurso !== null" @cerrar="emit('cerrar')">
        <p class="texto">
            <strong>{{ account.display_name }}</strong> · ahora:
            <span class="badge" :class="TONO_ACTUAL[account.delivery_status] ?? 'badge--off'">
                {{ account.delivery_status_label }}
            </span>
        </p>

        <div v-if="pasos.length > 0" class="pasos">
            <button
                v-for="p in pasos"
                :key="p.valor"
                type="button"
                class="paso"
                :disabled="enCurso !== null"
                @click="avanzar(p.valor)"
            >
                <Icon :name="p.icono" :size="20" />
                <span class="paso__datos">
                    <span class="paso__titulo">{{ enCurso === p.valor ? 'Guardando…' : p.titulo }}</span>
                    <span v-if="p.detalle" class="paso__detalle">{{ p.detalle }}</span>
                </span>
            </button>
        </div>
        <p v-else class="nota">Este pedido ya no tiene pasos de entrega pendientes.</p>

        <p class="nota">El avance sólo va hacia adelante: después no se puede regresar al estado anterior.</p>

        <p v-if="debe" class="alert alert--notice aviso">
            Aún falta cobrar {{ money(account.totals.due) }}: marcar la entrega no registra ningún cobro.
        </p>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="enCurso !== null" @click="emit('cerrar')">
                Volver
            </button>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.6; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }
.aviso { margin: 0; }

.pasos { display: grid; gap: 0.5rem; }

/* Cada paso es un botón grande con su significado: tocarlo es confirmarlo. */
.paso {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    width: 100%;
    min-height: 3.5rem;
    padding: 0.7rem 0.9rem;
    font: inherit;
    text-align: left;
    border: 1px solid color-mix(in srgb, var(--color-acento) 40%, var(--color-borde));
    border-radius: var(--radio);
    background: var(--color-superficie);
    color: var(--color-acento);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.paso:hover:not(:disabled) { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.paso:disabled { opacity: 0.6; cursor: not-allowed; }
.paso__datos { display: grid; gap: 0.15rem; min-width: 0; }
.paso__titulo { font-weight: 650; font-size: 0.98rem; }
.paso__detalle { font-size: 0.8rem; color: var(--color-suave); }
</style>
