<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { api, ApiError, getAllPages } from '../../api/client';
import { formatMoney as money } from '../../support/money';
import PosDialog from './PosDialog.vue';

/**
 * Juntar dos cuentas (§4.5): la mesa 4 se pasa con la 3 y pagan juntas.
 *
 * ## La cuenta de la URL es la que DESAPARECE
 *
 * Así lo define el servidor: `POST /pos-accounts/{origen}/merge` con `target_account_ulid` = el destino. Todos los
 * artículos del origen pasan al destino; el origen queda CANCELADO con el motivo «Juntada en la cuenta <folio>» y, si en
 * su mesa no queda otra cuenta viva, la mesa se libera. El titular que queda es el del destino. Responde con el destino.
 * Las dos cuentas tienen que estar vivas, sin pagos y en la misma sucursal (si no, 409).
 *
 * Desde esta pantalla sirven los dos sentidos, y la ventana los ofrece respetando esa convención:
 * - «Traer otra cuenta a ésta»: se llama al merge de la OTRA con ésta como destino. La versión que viaja es la de la otra
 *   —el candado optimista es del origen—, leída al elegirla. La respuesta es esta cuenta, que se queda en pantalla.
 * - «Mandar ésta a otra cuenta»: se llama al merge de ésta. Ésta queda cancelada, así que al terminar se salta al destino.
 *
 * ## Por qué se lee la otra cuenta completa
 *
 * El listado de cuentas abiertas no trae artículos. Al elegir una se pide entera: para decir cuántos artículos pasan, para
 * mandar su versión vigente y para advertir lo pendiente por enviar del origen, que el servidor mueve con su orden de
 * origen: en el destino ya no se podría mandar a cocina (409, «esa orden no pertenece a la cuenta»).
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },

    /** La sucursal activa: de ella salen las cuentas que se pueden juntar (el servidor rechaza las de otra). */
    branchUlid: { type: String, default: null },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const direccion = ref('traer'); // 'traer': la otra se junta en ésta · 'mandar': ésta se junta en la otra
const otraUlid = ref('');
const cuentas = ref([]);
const cargando = ref(true);
const errorCarga = ref(null);
const detalle = ref(null); // la otra cuenta, completa
const cargandoDetalle = ref(false);
const errorDetalle = ref(null);
const filtro = ref('');
const procesando = ref(false);
const error = ref(null);
const idFiltro = useId();
const idCuentas = useId();

onMounted(cargarCuentas);

async function cargarCuentas() {
    cargando.value = true;
    errorCarga.value = null;

    try {
        const filas = await getAllPages('/pos-accounts', { only_open: 1, branch: props.branchUlid });

        cuentas.value = filas
            .filter((c) => c.ulid !== props.account.ulid)
            .sort((a, b) => a.display_name.localeCompare(b.display_name, 'es', { numeric: true }));

        if (! cuentas.value.some((c) => c.ulid === otraUlid.value)) {
            otraUlid.value = '';
        }
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorCarga.value = e.message;
    } finally {
        cargando.value = false;
    }
}

// La lectura de la otra cuenta lleva turno: si se eligen dos seguidas, sólo cuenta la respuesta de la última.
let turno = 0;

async function cargarDetalle() {
    const ulid = otraUlid.value;
    const mio = ++turno;

    detalle.value = null;
    errorDetalle.value = null;

    if (! ulid) {
        cargandoDetalle.value = false;

        return;
    }

    cargandoDetalle.value = true;

    try {
        const { data } = await api.get(`/pos-accounts/${ulid}`);

        if (mio === turno) {
            detalle.value = data;
        }
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (mio === turno) {
            errorDetalle.value = e.message;
        }
    } finally {
        if (mio === turno) {
            cargandoDetalle.value = false;
        }
    }
}

watch(otraUlid, cargarDetalle);

/** Una cuenta con pagos no se junta (el servidor la rechaza): se ve, deshabilitada y con el porqué. */
const conPagos = (cuenta) => (cuenta.totals?.paid_total ?? '0.00') !== '0.00';

const cuentasVisibles = computed(() => {
    const q = filtro.value.trim().toLowerCase();

    if (q === '') {
        return cuentas.value;
    }

    return cuentas.value.filter((c) => [c.display_name, c.folio, c.waiter?.name]
        .some((texto) => (texto ?? '').toLowerCase().includes(q)));
});

/** El origen desaparece; el destino se queda con todo (y con su titular). */
const origen = computed(() => (direccion.value === 'traer' ? detalle.value : props.account));
const destino = computed(() => (direccion.value === 'traer' ? props.account : detalle.value));

/** Lo que pasa al destino: lo no cancelado del origen. */
const cuantosPasan = computed(() => (origen.value?.items ?? []).filter((i) => i.status !== 'cancelled').length);

/** Lo pendiente por enviar del origen: en el destino ya no se podría mandar a cocina (ver arriba). */
const pendientesOrigen = computed(() => (origen.value?.items ?? []).filter((i) => i.status === 'captured').length);

const puedeJuntar = computed(() => detalle.value !== null
    && ! cargandoDetalle.value
    && ! conPagos(detalle.value)
    && ! conPagos(props.account));

async function juntar() {
    if (procesando.value || ! puedeJuntar.value) {
        return;
    }

    procesando.value = true;
    error.value = null;

    const otra = detalle.value;

    try {
        if (direccion.value === 'traer') {
            // El origen es la OTRA: su merge, con SU versión y esta cuenta como destino. Vuelve esta cuenta.
            const { data } = await props.escribir(() => api.post(`/pos-accounts/${otra.ulid}/merge`, {
                version: otra.version,
                target_account_ulid: props.account.ulid,
            }));

            emit('hecho', { cuenta: data, aviso: `${otra.display_name} se juntó en esta cuenta.` });
        } else {
            const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/merge`, {
                version,
                target_account_ulid: otra.ulid,
            }));

            // Esta cuenta quedó cancelada: se sigue en la que la absorbió.
            emit('hecho', { ir: `/admin/pos/cuentas/${data.ulid}`, aviso: `Esta cuenta se juntó en ${data.display_name}.` });
        }
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;

        // Un 409: alguna de las dos cambió. Se releen ambas para que un reintento lleve las versiones al día.
        if (e.status === 409) {
            emit('refrescar');
            cargarDetalle();
        }
    } finally {
        procesando.value = false;
    }
}
</script>

<template>
    <PosDialog
        titulo="Juntar cuentas"
        ancho="amplio"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="juntar"
    >
        <fieldset class="grupo">
            <legend class="grupo__titulo">¿Cuál se queda?</legend>
            <div class="segmento">
                <button
                    type="button"
                    class="segmento__b"
                    :class="{ 'segmento__b--activo': direccion === 'traer' }"
                    :aria-pressed="direccion === 'traer'"
                    :disabled="procesando"
                    @click="direccion = 'traer'"
                >
                    <strong>Traer otra a ésta</strong>
                    <small>Esta cuenta se queda; la otra se cancela.</small>
                </button>
                <button
                    type="button"
                    class="segmento__b"
                    :class="{ 'segmento__b--activo': direccion === 'mandar' }"
                    :aria-pressed="direccion === 'mandar'"
                    :disabled="procesando"
                    @click="direccion = 'mandar'"
                >
                    <strong>Mandar ésta a otra</strong>
                    <small>La otra se queda; ésta se cancela.</small>
                </button>
            </div>
        </fieldset>

        <fieldset class="grupo">
            <legend class="grupo__titulo">{{ direccion === 'traer' ? 'Cuenta que se trae' : 'Cuenta que la recibe' }}</legend>

            <p v-if="cargando" class="nota" aria-live="polite">Cargando cuentas abiertas…</p>

            <div v-else-if="errorCarga" class="alert aviso" role="alert">
                {{ errorCarga }}
                <button type="button" class="link-button" @click="cargarCuentas">Reintentar</button>
            </div>

            <p v-else-if="cuentas.length === 0" class="nota">No hay otra cuenta abierta en la sucursal con la cual juntarla.</p>

            <template v-else>
                <div v-if="cuentas.length > 6" class="field campo">
                    <label :for="idFiltro" class="field__label">Buscar cuenta</label>
                    <input
                        :id="idFiltro"
                        v-model="filtro"
                        type="search"
                        class="input"
                        placeholder="Mesa, folio o mesero"
                        autocomplete="off"
                        @keydown.enter.prevent
                    />
                </div>

                <ul class="lista">
                    <li v-for="c in cuentasVisibles" :key="c.ulid">
                        <label
                            class="opcion"
                            :class="{ 'opcion--elegida': otraUlid === c.ulid, 'opcion--bloqueada': conPagos(c) }"
                        >
                            <input
                                v-model="otraUlid"
                                type="radio"
                                :name="idCuentas"
                                :value="c.ulid"
                                :disabled="procesando || conPagos(c)"
                            />
                            <span class="opcion__datos">
                                <span class="opcion__nombre">{{ c.display_name }}</span>
                                <span class="opcion__detalle">
                                    {{ c.status_label }}<template v-if="c.waiter"> · {{ c.waiter.name }}</template>
                                    <template v-if="conPagos(c)"> · ya tiene pagos: no se puede juntar</template>
                                </span>
                            </span>
                            <span class="opcion__importe">{{ money(c.totals.total) }}</span>
                        </label>
                    </li>
                    <li v-if="cuentasVisibles.length === 0" class="nota">Ninguna cuenta coincide con la búsqueda.</li>
                </ul>
            </template>
        </fieldset>

        <p v-if="cargandoDetalle" class="nota" aria-live="polite">Leyendo la cuenta elegida…</p>

        <div v-else-if="errorDetalle" class="alert aviso" role="alert">
            {{ errorDetalle }}
            <button type="button" class="link-button" @click="cargarDetalle">Reintentar</button>
        </div>

        <!-- La consecuencia, con los nombres reales y en el sentido elegido. -->
        <div v-else-if="origen && destino" class="consecuencia">
            <p class="texto">
                <template v-if="cuantosPasan === 0">
                    <strong>{{ origen.display_name }}</strong> no tiene artículos: sólo se cancela.
                </template>
                <template v-else>
                    {{ cuantosPasan === 1 ? 'El único artículo' : `Los ${cuantosPasan} artículos` }} de
                    <strong>{{ origen.display_name }}</strong> {{ cuantosPasan === 1 ? 'pasa' : 'pasan' }} a
                    <strong>{{ destino.display_name }}</strong>.
                </template>
                {{ origen.display_name }} queda cancelada con el motivo «Juntada en la cuenta {{ destino.folio }}», y no se
                puede deshacer.
            </p>
            <p class="nota">
                <template v-if="origen.table">
                    La mesa {{ origen.table.code }} se libera si no queda otra cuenta abierta en ella.
                </template>
                El titular que queda es el de {{ destino.display_name }}<template v-if="destino.waiter">
                    ({{ destino.waiter.name }})</template>. Las comandas ya enviadas no se reimprimen.
            </p>

            <p v-if="pendientesOrigen > 0" class="alert alert--notice aviso">
                {{ origen.display_name }} tiene
                {{ pendientesOrigen === 1 ? '1 artículo pendiente' : `${pendientesOrigen} artículos pendientes` }} por
                enviar: mándalos a preparar o quítalos antes de juntar. Si pasan así, en {{ destino.display_name }} ya no se
                podrán mandar a cocina.
            </p>
        </div>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                Volver
            </button>
            <button type="submit" class="button" :disabled="procesando || ! puedeJuntar">
                <template v-if="procesando">Juntando…</template>
                <template v-else-if="detalle && direccion === 'traer'">Traer {{ detalle.display_name }} aquí</template>
                <template v-else-if="detalle">Juntar en {{ detalle.display_name }}</template>
                <template v-else>Juntar</template>
            </button>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.5; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; line-height: 1.45; }
.aviso { margin: 0; }
.campo { margin: 0; }
.consecuencia { display: grid; gap: 0.5rem; }

.grupo { display: grid; gap: 0.5rem; margin: 0; padding: 0; border: 0; min-width: 0; }
.grupo__titulo { padding: 0; margin-bottom: 0.1rem; font-size: 0.85rem; font-weight: 650; }

/* El sentido, en dos botones grandes: el mismo segmentado de la cancelación de un comandado. */
.segmento { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.5rem; }
.segmento__b {
    display: grid;
    gap: 0.2rem;
    min-height: 3.2rem;
    padding: 0.65rem 0.75rem;
    font: inherit;
    font-size: 0.9rem;
    text-align: left;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    color: var(--color-contenido);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.segmento__b small { font-size: 0.78rem; color: var(--color-suave); }
.segmento__b:hover:not(:disabled) { border-color: var(--color-acento); }
.segmento__b--activo { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 12%, transparent); }
.segmento__b--activo strong { color: var(--color-acento); }
.segmento__b:disabled { opacity: 0.6; cursor: not-allowed; }

.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; }

.opcion {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-height: 2.9rem;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.opcion:hover:not(.opcion--bloqueada) { border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde)); }
.opcion--elegida { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.opcion--bloqueada { cursor: not-allowed; opacity: 0.6; }
.opcion input { flex: none; width: 1.15rem; height: 1.15rem; margin: 0; accent-color: var(--color-acento); }
.opcion__datos { flex: 1; display: grid; gap: 0.1rem; min-width: 0; }
.opcion__nombre { font-weight: 600; font-size: 0.92rem; overflow-wrap: anywhere; }
.opcion__detalle { font-size: 0.78rem; color: var(--color-suave); }
.opcion__importe { flex: none; font-variant-numeric: tabular-nums; font-size: 0.9rem; }
</style>
