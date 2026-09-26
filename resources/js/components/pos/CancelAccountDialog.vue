<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { api, ApiError } from '../../api/client';
import Icon from '../Icon.vue';
import PosDialog from './PosDialog.vue';

/**
 * Cancelar una cuenta completa (§6.3): la mesa que se abrió por error, el cliente que se fue sin consumir.
 *
 * ## Lo que hace el servidor, y lo que NO hace, dicho antes de confirmar
 *
 * `POST /pos-accounts/{cuenta}/cancel` pide sólo el motivo (de 3 a 300 caracteres) y admite una cuenta «Abierta» o con
 * «Cuenta solicitada» que no tenga pagos. La deja «Cancelada» para siempre —no se reabre—, lo registra en la bitácora a
 * nombre de quien la cancela y libera su mesa si no queda otra cuenta viva en ella. Responde con la cuenta; la pantalla
 * vuelve a la lista.
 *
 * No pide PIN: la ruta exige `pos.items.cancel_commanded` al rol activo, así que esta opción sólo la ve quien lo tiene.
 * Y no toca lo ya comandado: no manda cancelación a cocina ni a barra (lo siguen viendo en su tablero) ni registra merma
 * o reingreso. Cancelar cada artículo desde «Enviados» sí hace todo eso, así que, si hay artículos enviados, la ventana
 * lo dice con todas sus letras antes de confirmar, en vez de dejar que se descubra en la cocina.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const motivo = ref('');
const procesando = ref(false);
const error = ref(null);
const errorMotivo = ref(null);
const campo = ref(null);
const idMotivo = useId();

onMounted(() => campo.value?.focus());

/** Lo ya enviado a preparar y sin cancelar: lo que la cancelación de la cuenta no le avisa a nadie. */
const enviados = computed(() => (props.account.items ?? []).filter((i) => i.was_commanded && i.status !== 'cancelled'));

/** Lo capturado que nunca salió a preparar: se queda en la cuenta cancelada y nadie lo prepara. */
const pendientes = computed(() => (props.account.items ?? []).filter((i) => i.status === 'captured'));

const motivoValido = computed(() => motivo.value.trim().length >= 3);

async function confirmar() {
    if (procesando.value || ! motivoValido.value) {
        return;
    }

    procesando.value = true;
    error.value = null;
    errorMotivo.value = null;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/cancel`, {
            // El servidor no revisa la versión al cancelar; viaja igual, como en toda escritura de la cuenta.
            version,
            reason: motivo.value.trim(),
        }));

        emit('hecho', { aviso: `${data.display_name}: cuenta cancelada.`, ir: '/admin/pos/cuentas' });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (e.isValidation) {
            errorMotivo.value = e.fieldErrors.reason ?? e.message;

            return;
        }

        error.value = e.message;

        // Un 409 es que la cuenta ya no está como se ve (la cobraron, otra terminal la movió): se vuelve a leer.
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
        titulo="Cancelar la cuenta"
        ancho="chico"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="confirmar"
    >
        <p class="texto">
            <strong>{{ account.display_name }}</strong> (folio {{ account.folio }}) quedará cancelada: ya no se podrá cobrar
            ni reabrir, y queda en la bitácora a tu nombre con el motivo.
            <template v-if="account.table">
                Si no queda otra cuenta abierta en la mesa {{ account.table.code }}, la mesa se libera.
            </template>
        </p>

        <div v-if="enviados.length > 0" class="alert alert--notice aviso">
            <p>
                <strong>
                    {{ enviados.length === 1 ? 'Hay 1 artículo ya enviado' : `Hay ${enviados.length} artículos ya enviados` }}
                    a preparar.
                </strong>
                Cancelar la cuenta no le avisa a cocina ni a barra —lo siguen viendo en su tablero— y no registra merma ni
                reingreso en el inventario.
            </p>
            <p>Si algo se tira o vuelve a existencias, cancélalo antes en «Enviados» (⋮ → Cancelar artículo).</p>
        </div>

        <p v-if="pendientes.length > 0" class="nota">
            Lo pendiente por enviar no llega a la cocina: se queda en la cuenta cancelada.
        </p>

        <div class="field campo">
            <label :for="idMotivo" class="field__label">Motivo de la cancelación</label>
            <textarea
                :id="idMotivo"
                ref="campo"
                v-model="motivo"
                class="input"
                rows="2"
                minlength="3"
                maxlength="300"
                required
                :aria-invalid="errorMotivo ? 'true' : 'false'"
                :aria-describedby="`${idMotivo}-ayuda`"
            ></textarea>
            <span v-if="errorMotivo" :id="`${idMotivo}-ayuda`" class="field__error" role="alert">{{ errorMotivo }}</span>
            <span v-else :id="`${idMotivo}-ayuda`" class="field__hint">
                Al menos 3 caracteres. Por ejemplo: «Se abrió en la mesa equivocada».
            </span>
        </div>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                Volver
            </button>
            <button type="submit" class="button button--danger" :disabled="procesando || ! motivoValido">
                <Icon name="trash" :size="16" /> {{ procesando ? 'Cancelando…' : 'Cancelar la cuenta' }}
            </button>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.5; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }

.aviso { margin: 0; }
.aviso p { margin: 0; line-height: 1.45; }
.aviso p + p { margin-top: 0.4rem; }

.campo { margin: 0; }
.campo textarea { resize: vertical; min-height: 3.4rem; }
</style>
