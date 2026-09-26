<script>
/**
 * La clave con la que la BASE compara nombres de planos y de zonas.
 *
 * `utf8mb4_0900_ai_ci` no distingue mayúsculas ni acentos («Salón» = «salon»), y el servidor recorta los espacios de los
 * extremos antes de guardar. Los índices únicos —plano por sucursal, zona por plano— comparan así, y hoy un repetido no
 * vuelve como un 422 sino como un error de integridad sin texto útil. La pantalla lo previene con la MISMA regla:
 * previsualiza; quien decide sigue siendo el servidor.
 *
 * Vive en el `<script>` normal, y no en el de `setup`, para poder exportarla: el editor la usa al renombrar un plano.
 */
export function claveNombre(texto) {
    return String(texto ?? '')
        .trim()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLocaleLowerCase('es');
}
</script>

<script setup>
import { computed, nextTick, onMounted, ref, useId } from 'vue';
import Icon from '../Icon.vue';
import FormHeader from '../FormHeader.vue';

/**
 * Alta de un plano del salón con sus zonas, en un solo envío (D34).
 *
 * ## Las zonas van con el plano, y al menos una
 *
 * Toda mesa vive en una zona, así que un plano sin zonas no admite mesas: el servidor exige al menos una en la misma
 * petición. El formulario nace con una sugerida, no deja quitar la última y no manda las filas vacías — una fila en
 * blanco no es una zona.
 *
 * ## Controlado: el envío lo hace el editor
 *
 * Crear un plano lo abre en el editor, y eso deja el plano que se estaba editando: hay que guardar antes lo pendiente,
 * crear y cargar el nuevo. Esa coreografía es del editor. Aquí vive el formulario: emite `enviar` con `{ name, zones }`
 * y recibe de vuelta si se está enviando y qué respondió el servidor.
 */
const props = defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },

    /** El primer plano de la sucursal: dice cuándo no hace falta uno y no ofrece cancelar (no hay a dónde volver). */
    primero: { type: Boolean, default: false },

    /** Los nombres de los planos que ya tiene la sucursal, para avisar del repetido antes de mandarlo. */
    nombresOcupados: { type: Array, default: () => [] },

    procesando: { type: Boolean, default: false },

    /** Errores por campo tal como los deja `useApiForm` (`name`, `zones`, `zones.0`…). */
    errores: { type: Object, default: () => ({}) },

    /** El mensaje general del último envío fallido. */
    error: { type: String, default: null },
});

const emit = defineEmits(['enviar', 'cancelar']);

/** Lo más que acepta el servidor en una sola alta. */
const MAX_ZONAS = 20;

/** Zonas que casi todo salón tiene además del salón mismo: un toque en lugar de teclearlas. */
const SUGERENCIAS = ['Terraza', 'Barra', 'Privado'];

const uid = useId();
const idNombre = `${uid}-nombre`;
const idZona = (z) => `${uid}-zona-${z.id}`;

let siguienteId = 1;
const nuevaFila = (texto = '') => ({ id: siguienteId++, nombre: texto });

const nombre = ref(props.primero ? 'Salón principal' : '');
const zonas = ref([nuevaFila('Salón')]);
const campoNombre = ref(null);

/** Si ya se intentó enviar: los «falta esto» se callan hasta entonces, para no regañar a un formulario recién abierto. */
const intentado = ref(false);

/** A qué fila corresponde cada posición del arreglo enviado —las vacías no viajan—, para pintar ahí el error del servidor. */
const enviadas = ref([]);

const zonasEscritas = computed(() => zonas.value.map((z) => z.nombre.trim()).filter((texto) => texto !== ''));

const nombreRepetido = computed(() => {
    const clave = claveNombre(nombre.value);

    return clave !== '' && props.nombresOcupados.some((otro) => claveNombre(otro) === clave);
});

/** Las filas que repiten una zona anterior de la lista: la primera se queda, las siguientes se marcan. */
const repetidas = computed(() => {
    const vistas = new Set();
    const ids = new Set();

    for (const z of zonas.value) {
        const clave = claveNombre(z.nombre);

        if (clave === '') {
            continue;
        }

        if (vistas.has(clave)) {
            ids.add(z.id);
        } else {
            vistas.add(clave);
        }
    }

    return ids;
});

const errorNombre = computed(() => {
    if (nombreRepetido.value) {
        return 'Ya hay un plano con ese nombre en esta sucursal.';
    }

    if (intentado.value && nombre.value.trim() === '') {
        return 'Escribe el nombre del plano.';
    }

    return props.errores.name ?? null;
});

function errorDeZona(z) {
    if (repetidas.value.has(z.id)) {
        return 'Esta zona ya está en la lista.';
    }

    const posicion = enviadas.value.indexOf(z.id);

    return posicion === -1 ? null : (props.errores[`zones.${posicion}`] ?? null);
}

const errorZonas = computed(() => {
    if (intentado.value && zonasEscritas.value.length === 0) {
        return 'Escribe al menos una zona: toda mesa vive en una zona del salón.';
    }

    return props.errores.zones ?? null;
});

/** Las sugerencias que todavía no están en la lista. */
const sugerencias = computed(() => SUGERENCIAS.filter(
    (s) => ! zonas.value.some((z) => claveNombre(z.nombre) === claveNombre(s)),
));

const llena = computed(() => zonas.value.length >= MAX_ZONAS);

function agregarZona(texto = '') {
    if (llena.value) {
        return;
    }

    const fila = nuevaFila(texto);
    zonas.value.push(fila);

    // Una fila en blanco es para escribir en ella; una sugerencia ya viene escrita.
    if (texto === '') {
        nextTick(() => document.getElementById(idZona(fila))?.focus());
    }
}

function quitarZona(z) {
    if (zonas.value.length > 1) {
        zonas.value = zonas.value.filter((otra) => otra.id !== z.id);
    }
}

function enviar() {
    if (props.procesando) {
        return;
    }

    intentado.value = true;

    // Lo que el servidor rechazaría (o reventaría: los repetidos) no se manda. El foco va a lo que hay que corregir.
    if (nombre.value.trim() === '' || nombreRepetido.value) {
        campoNombre.value?.focus();

        return;
    }

    if (zonasEscritas.value.length === 0 || repetidas.value.size > 0) {
        const aCorregir = zonas.value.find((z) => repetidas.value.has(z.id)) ?? zonas.value[0];
        document.getElementById(idZona(aCorregir))?.focus();

        return;
    }

    const llenas = zonas.value.filter((z) => z.nombre.trim() !== '');
    enviadas.value = llenas.map((z) => z.id);

    emit('enviar', { name: nombre.value.trim(), zones: llenas.map((z) => z.nombre.trim()) });
}

// El foco, cuando se abrió a propósito («Nuevo plano»). El del primer plano aparece al entrar a la pantalla, y ahí
// robarle el foco a quien apenas llega sería más estorbo que ayuda.
onMounted(() => {
    if (! props.primero) {
        campoNombre.value?.focus();
    }
});
</script>

<template>
    <section class="plano-form tarjeta" :aria-label="titulo">
        <FormHeader :title="titulo" :subtitle="subtitulo" />

        <p v-if="primero" class="plano-form__nota">
            Si en esta sucursal no hay mesas —sólo mostrador, barra o para llevar—, no necesitas plano: esas ventas se
            abren sin mesa.
        </p>

        <form class="plano-form__cuerpo" novalidate @submit.prevent="enviar">
            <div class="field">
                <label :for="idNombre" class="field__label">Nombre del plano</label>
                <input
                    :id="idNombre"
                    ref="campoNombre"
                    v-model="nombre"
                    class="input"
                    type="text"
                    maxlength="60"
                    autocomplete="off"
                    :placeholder="primero ? 'p. ej. Salón principal' : 'p. ej. Terraza de verano'"
                    :aria-invalid="errorNombre ? 'true' : null"
                    :aria-describedby="errorNombre ? `${idNombre}-error` : null"
                />
                <span v-if="errorNombre" :id="`${idNombre}-error`" class="field__error" role="alert">
                    {{ errorNombre }}
                </span>
            </div>

            <fieldset class="plano-form__zonas">
                <legend class="field__label">Zonas</legend>
                <p class="plano-form__nota">
                    Las áreas del salón donde van las mesas: toda mesa vive en una. Podrás cambiarlas después.
                </p>

                <ul class="plano-form__lista">
                    <li v-for="(z, i) in zonas" :key="z.id">
                        <div class="plano-form__fila">
                            <label :for="idZona(z)" class="sr-only">Zona {{ i + 1 }}</label>
                            <input
                                :id="idZona(z)"
                                v-model="z.nombre"
                                class="input"
                                type="text"
                                maxlength="60"
                                autocomplete="off"
                                :placeholder="i === 0 ? 'p. ej. Salón' : 'p. ej. Terraza'"
                                :aria-invalid="errorDeZona(z) ? 'true' : null"
                                :aria-describedby="errorDeZona(z) ? `${idZona(z)}-error` : null"
                            />
                            <button
                                type="button"
                                class="plano-form__quitar"
                                :title="zonas.length > 1 ? 'Quitar esta zona' : 'Un plano necesita al menos una zona'"
                                :aria-label="`Quitar la zona ${z.nombre.trim() || i + 1}`"
                                :disabled="zonas.length <= 1 || procesando"
                                @click="quitarZona(z)"
                            >
                                <Icon name="x" />
                            </button>
                        </div>
                        <span v-if="errorDeZona(z)" :id="`${idZona(z)}-error`" class="field__error" role="alert">
                            {{ errorDeZona(z) }}
                        </span>
                    </li>
                </ul>

                <div class="plano-form__agregar">
                    <button type="button" class="link-button" :disabled="llena || procesando" @click="agregarZona()">
                        <Icon name="plus" /> Agregar zona
                    </button>
                    <button
                        v-for="s in sugerencias"
                        :key="s"
                        type="button"
                        class="plano-form__sugerencia"
                        :aria-label="`Agregar la zona ${s}`"
                        :disabled="llena || procesando"
                        @click="agregarZona(s)"
                    >
                        + {{ s }}
                    </button>
                </div>

                <span v-if="errorZonas" class="field__error" role="alert">{{ errorZonas }}</span>
            </fieldset>

            <!-- Lo que quien envía tiene que saber de este alta en particular (lo pone el editor). -->
            <slot />

            <p v-if="error" class="alert plano-form__alerta" role="alert">{{ error }}</p>

            <div class="plano-form__acciones">
                <button type="submit" class="button" :disabled="procesando"><Icon name="plus" /> Crear plano</button>
                <button
                    v-if="!primero"
                    type="button"
                    class="link-button"
                    :disabled="procesando"
                    @click="emit('cancelar')"
                >
                    <Icon name="x" /> Cancelar
                </button>
            </div>
        </form>
    </section>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.plano-form { display: grid; gap: 0.75rem; padding: 1rem 1.1rem; max-width: 42rem; }
.plano-form__nota { margin: 0; font-size: 0.85rem; line-height: 1.45; color: var(--color-suave); }
.plano-form__cuerpo { display: grid; gap: 1rem; }
.plano-form__cuerpo .field { margin: 0; }

/* `min-width: 0`: un fieldset reclama por omisión el ancho de su contenido y desbordaría a 375 px. */
.plano-form__zonas { display: grid; gap: 0.5rem; min-width: 0; margin: 0; padding: 0; border: 0; }
.plano-form__zonas legend { padding: 0; }
.plano-form__lista { display: grid; gap: 0.45rem; margin: 0; padding: 0; list-style: none; }
.plano-form__fila { display: flex; align-items: center; gap: 0.4rem; }
.plano-form__fila .input { flex: 1 1 auto; }

.plano-form__quitar {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    color: var(--color-suave);
    cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
}
.plano-form__quitar:hover:not(:disabled) {
    color: var(--color-peligro);
    border-color: color-mix(in srgb, var(--color-peligro) 45%, transparent);
    background: color-mix(in srgb, var(--color-peligro) 8%, transparent);
}
.plano-form__quitar:disabled { opacity: 0.4; cursor: not-allowed; }

.plano-form__agregar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
.plano-form__sugerencia {
    font: inherit;
    font-size: 0.8rem;
    padding: 0.26rem 0.7rem;
    cursor: pointer;
    border: 1px dashed var(--color-borde);
    border-radius: 999px;
    background: var(--color-superficie);
    color: var(--color-suave);
    transition: border-color 0.15s ease, color 0.15s ease;
}
.plano-form__sugerencia:hover:not(:disabled) { border-color: var(--color-acento); color: var(--color-acento); }
.plano-form__sugerencia:disabled { opacity: 0.5; cursor: not-allowed; }

.plano-form__alerta { margin: 0; }
.plano-form__acciones { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }

/* Nombre sólo para lectores de pantalla: cada fila de zona se identifica sin repetir «Zona» a la vista. */
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
</style>
