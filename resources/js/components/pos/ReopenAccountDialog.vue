<script setup>
import { ref } from 'vue';
import { api, ApiError } from '../../api/client';
import Icon from '../Icon.vue';
import PosDialog from './PosDialog.vue';

/**
 * Reabrir una cuenta (§6.3): pidieron la cuenta y luego otra cerveza, o hay que corregir una cuenta ya cerrada.
 *
 * `POST /pos-accounts/{cuenta}/reopen`, sólo con `version`. El servidor la regresa a «Abierta» desde «Cuenta solicitada»
 * o «Cerrada» (es lo que publica en `allowed_next`), recalcula el total y lo registra en la bitácora con el estado del
 * que venía. La ruta exige `pos.accounts.reopen` incluso desde «Cuenta solicitada», así que la opción sólo la ve quien
 * lo tiene. Devuelve la cuenta, y con ella vuelve el catálogo para seguir marcando.
 *
 * Es el único camino de vuelta a marcar: la pantalla sólo ofrece el catálogo con la cuenta «Abierta», y pedir la
 * precuenta la pasa a «Cuenta solicitada».
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const procesando = ref(false);
const error = ref(null);

async function reabrir() {
    if (procesando.value) {
        return;
    }

    procesando.value = true;
    error.value = null;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/reopen`, { version }));

        emit('hecho', { cuenta: data, aviso: 'Cuenta reabierta: ya puedes agregar artículos.' });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.message;

        if (e.status === 409) {
            emit('refrescar');
        }
    } finally {
        procesando.value = false;
    }
}
</script>

<template>
    <PosDialog
        titulo="Reabrir la cuenta"
        ancho="chico"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="reabrir"
    >
        <p v-if="account.status === 'closed'" class="texto">
            Se deshace el cierre de <strong>{{ account.display_name }}</strong>: el total deja de estar fijado y la cuenta
            vuelve a «Abierta» para agregarle artículos.
        </p>
        <p v-else class="texto">
            <strong>{{ account.display_name }}</strong> vuelve a «Abierta» para agregarle artículos. Si agregas algo, la
            precuenta impresa deja de valer: imprímela de nuevo antes de cobrar.
        </p>

        <p class="nota">Queda registrado en la bitácora a tu nombre.</p>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                Volver
            </button>
            <button type="submit" class="button" :disabled="procesando">
                <Icon name="undo" :size="16" /> {{ procesando ? 'Reabriendo…' : 'Reabrir la cuenta' }}
            </button>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.5; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }
.aviso { margin: 0; }
</style>
