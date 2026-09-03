<script setup>
import { ref, watch } from 'vue';
import { api, ApiError } from '../../api/client';
import Icon from '../../components/Icon.vue';

/**
 * Diálogo de autorización por PIN (ADR-008).
 *
 * ## Qué problema resuelve, y por qué es un componente aparte
 *
 * Varias operaciones responden **409 con `authorization_required`** cuando pasan un umbral de monto: una merma sobre el
 * umbral, el cierre de un conteo grande, y en la Iteración 5 los descuentos y las cancelaciones. El contrato es uno
 * solo a propósito (`RequiresAuthorizationException`), así que el diálogo también: el 409 trae **qué permiso hace
 * falta**, y esta pantalla lo pide sin tener que saber de qué operación venía.
 *
 * ## Lo que NO hace
 *
 * No guarda el token, no lo reusa y no lo pide «por si acaso». Una concesión es de **un solo uso** y se gasta al
 * consumirla, así que pedirla antes de saber si hace falta desperdiciaría la que el usuario acababa de conseguir para
 * otra cosa. El flujo es: intentar → recibir 409 → pedir el PIN → reintentar con el token.
 *
 * ## El PIN no se recuerda ni se prellena
 *
 * Es de otra persona: quien autoriza pone su código de empleado y su PIN en la terminal de quien opera. Recordarlo
 * convertiría una firma en un trámite, que es exactamente lo que el umbral existe para evitar.
 */
const props = defineProps({
    /** El permiso que el servidor dijo que hace falta, tal como vino en el 409. */
    requiredPermission: { type: String, required: true },

    /** El mensaje del servidor: dice el monto y el umbral, y por qué hace falta la firma. */
    reason: { type: String, default: '' },
});

const emit = defineEmits(['granted', 'cancelled']);

const employeeCode = ref('');
const pin = ref('');
const processing = ref(false);
const error = ref(null);
const authorizer = ref(null);

watch(() => props.requiredPermission, () => {
    employeeCode.value = '';
    pin.value = '';
    error.value = null;
    authorizer.value = null;
});

async function request() {
    processing.value = true;
    error.value = null;

    try {
        const response = await api.post('/authorizations', {
            employee_code: employeeCode.value,
            pin: pin.value,
            permission: props.requiredPermission,
        });

        authorizer.value = response.data.authorized_by;

        // El token sube al llamador, que reintenta la operación con él. No se guarda aquí: si el reintento falla por
        // otra razón, el token ya se gastó y pedirlo otra vez es lo correcto.
        emit('granted', response.data.token, response.data.authorized_by);
    } catch (e) {
        if (!(e instanceof ApiError)) {
            throw e;
        }

        // Un PIN incorrecto y un código inexistente dan el MISMO mensaje a propósito (ADR-008): distinguirlos
        // permitiría enumerar códigos de empleado válidos. Se muestra tal cual viene del servidor.
        error.value = e.message;
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <div class="drawer-backdrop" @click.self="emit('cancelled')">
        <form class="drawer drawer--narrow" @submit.prevent="request">
            <h2>Hace falta una autorización</h2>

            <p v-if="props.reason" class="pin__reason">{{ props.reason }}</p>

            <p class="pin__hint">
                Esta operación necesita el PIN de alguien con permiso para autorizarla. La autorización queda
                registrada a su nombre y sirve <strong>una sola vez</strong>.
            </p>

            <p v-if="error" class="alert">{{ error }}</p>

            <label class="field">
                <span class="field__label">Código de empleado de quien autoriza</span>
                <input v-model="employeeCode" class="input" required autocomplete="off" />
            </label>

            <label class="field">
                <span class="field__label">PIN</span>
                <!-- `type=password` y sin autocompletar: es el PIN de otra persona, escrito en una terminal ajena. -->
                <input v-model="pin" type="password" class="input" required autocomplete="off" inputmode="numeric" />
            </label>

            <div class="drawer__actions">
                <button type="button" class="link-button" @click="emit('cancelled')"><Icon name="x" /> Cancelar</button>
                <button type="submit" class="button" :disabled="processing">
                    {{ processing ? 'Autorizando…' : 'Autorizar y continuar' }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.drawer--narrow {
    max-width: 24rem;
}

.pin__reason {
    margin: 0 0 0.6rem;
    padding: 0.55rem 0.7rem;
    border-left: 3px solid var(--color-aviso);
    background: var(--color-aviso-tenue);
    font-size: 0.9rem;
}

.pin__hint {
    margin: 0 0 0.9rem;
    color: var(--color-suave);
    font-size: 0.85rem;
}
</style>
