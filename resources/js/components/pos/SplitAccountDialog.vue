<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { api, ApiError } from '../../api/client';
import { formatMoney as money } from '../../support/money';
import PosDialog from './PosDialog.vue';

/**
 * Dividir la cuenta en partes iguales (§6.3, §4.5): «cada quien paga lo suyo».
 *
 * ## Se reparte el IMPORTE, no los artículos
 *
 * Así lo hace el servidor (`AccountOperations::split`): `POST /pos-accounts/{cuenta}/split` recibe sólo `parts` (de 2 a
 * 20) y crea esas cuentas nuevas colgando de ésta, cada una con su parte del total. Los artículos y la mesa se quedan
 * aquí; cada parte se cobra sola y ésta queda pagada cuando todas sus partes lo están. Por eso la ventana no ofrece
 * elegir líneas ni cantidades: el endpoint no las recibe. Responde con las PARTES, no con esta cuenta.
 *
 * El servidor rechaza (409) una cuenta con pagos, una ya dividida, una parte de otra división y una sin consumo.
 *
 * ## Cuánto es cada parte lo dice el servidor
 *
 * Aquí no se divide dinero: 100 entre 3 no da partes iguales y el centavo que sobra tiene dueño (la primera parte). La
 * ventana muestra el total que se reparte y, al terminar, el importe exacto de cada parte tal como volvió, con el acceso
 * a cobrarla.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

// Los límites del servidor: una parte no es dividir, y más de veinte es un error de dedo que crearía veinte folios.
const MIN_PARTES = 2;
const MAX_PARTES = 20;

const partes = ref(MIN_PARTES);
const procesando = ref(false);
const error = ref(null);
const creadas = ref(null); // las partes que devolvió el servidor
const campo = ref(null);
const idPartes = useId();

onMounted(() => campo.value?.focus());

const partesValidas = computed(() => Number.isInteger(partes.value) && partes.value >= MIN_PARTES && partes.value <= MAX_PARTES);

/** Los botones −/+: siempre dentro de los límites, aunque el campo tenga algo a medio teclear. */
function ajustar(delta) {
    const actual = Number.isInteger(partes.value) ? partes.value : MIN_PARTES;

    partes.value = Math.min(MAX_PARTES, Math.max(MIN_PARTES, actual + delta));
}

async function dividir() {
    if (procesando.value || creadas.value || ! partesValidas.value) {
        return;
    }

    procesando.value = true;
    error.value = null;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/split`, {
            version,
            parts: partes.value,
        }));

        creadas.value = data;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (e.fieldErrors.parts ?? e.message) : e.message;

        if (e.status === 409) {
            emit('refrescar');
        }
    } finally {
        procesando.value = false;
    }
}

const irAParte = (parte) => emit('hecho', { ir: `/admin/pos/cuentas/${parte.ulid}` });
</script>

<template>
    <PosDialog
        :titulo="creadas ? 'Cuenta dividida' : 'Dividir en partes iguales'"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="dividir"
    >
        <template v-if="! creadas">
            <p class="texto">
                Se reparte el total de <strong>{{ money(account.totals.total) }}</strong> en partes iguales: se crean cuentas
                nuevas, una por parte, que se cobran por separado. Los artículos y la mesa se quedan en esta cuenta.
            </p>

            <div class="field campo">
                <label :for="idPartes" class="field__label">¿En cuántas partes?</label>
                <div class="contador">
                    <button
                        type="button"
                        class="contador__b"
                        aria-label="Una parte menos"
                        :disabled="procesando || partes <= MIN_PARTES"
                        @click="ajustar(-1)"
                    >−</button>
                    <input
                        :id="idPartes"
                        ref="campo"
                        v-model.number="partes"
                        class="input contador__n"
                        type="number"
                        inputmode="numeric"
                        :min="MIN_PARTES"
                        :max="MAX_PARTES"
                        step="1"
                        required
                        :aria-describedby="`${idPartes}-ayuda`"
                    />
                    <button
                        type="button"
                        class="contador__b"
                        aria-label="Una parte más"
                        :disabled="procesando || partes >= MAX_PARTES"
                        @click="ajustar(1)"
                    >+</button>
                </div>
                <span :id="`${idPartes}-ayuda`" class="field__hint">
                    De {{ MIN_PARTES }} a {{ MAX_PARTES }}. Si el total no se reparte exacto, el centavo que sobra va a la
                    primera parte.
                </span>
            </div>

            <div class="alert alert--notice aviso">
                <p>
                    Después, cobra las partes y no esta cuenta: cobrarla completa sería cobrar dos veces. Esta cuenta se da
                    por pagada sola cuando todas sus partes lo estén.
                </p>
                <p>Lo que le agregues a esta cuenta después de dividirla no entra en ninguna parte.</p>
            </div>

            <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>
        </template>

        <template v-else>
            <p class="texto">
                {{ creadas.length === 1 ? 'Se creó 1 cuenta' : `Se crearon ${creadas.length} cuentas` }}. Cóbralas por
                separado:
            </p>

            <ul class="partes">
                <li v-for="parte in creadas" :key="parte.ulid" class="parte">
                    <span class="parte__datos">
                        <span class="parte__nombre">{{ parte.display_name }}</span>
                        <span class="parte__folio">{{ parte.folio }}</span>
                    </span>
                    <strong class="parte__importe">{{ money(parte.totals.total) }}</strong>
                    <button type="button" class="button button--ghost parte__ir" @click="irAParte(parte)">
                        Ir a cobrar
                    </button>
                </li>
            </ul>

            <p class="nota">También aparecen en la lista de cuentas abiertas.</p>
        </template>

        <template #acciones>
            <template v-if="! creadas">
                <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                    Volver
                </button>
                <button type="submit" class="button" :disabled="procesando || ! partesValidas">
                    {{ procesando ? 'Dividiendo…' : (partesValidas ? `Dividir en ${partes} partes` : 'Dividir') }}
                </button>
            </template>
            <button v-else type="button" class="button button--neutral" @click="emit('cerrar')">
                Quedarme en esta cuenta
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

/* El contador de partes: −/+ grandes, como los del ticket, y el número tecleable en medio. */
.contador { display: flex; align-items: center; gap: 0.5rem; }
.contador__b {
    flex: none;
    display: grid;
    place-items: center;
    width: 3rem;
    height: 3rem;
    font: inherit;
    font-size: 1.5rem;
    line-height: 1;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.contador__b:hover:not(:disabled) { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.contador__b:disabled { opacity: 0.45; cursor: not-allowed; }
.field .contador__n {
    width: 5rem;
    height: 3rem;
    text-align: center;
    font-size: 1.25rem;
    font-weight: 650;
    font-variant-numeric: tabular-nums;
}

/* Las partes creadas: nombre y folio, importe exacto del servidor y el acceso a cobrarla. */
.partes { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.parte {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.9rem;
    padding: 0.65rem 0.8rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
}
.parte__datos { flex: 1 1 10rem; display: grid; gap: 0.1rem; min-width: 0; }
.parte__nombre { font-weight: 600; }
.parte__folio { font-size: 0.78rem; color: var(--color-suave); }
.parte__importe { font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.parte__ir { min-height: 2.75rem; }
</style>
