<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue';
import { api, ApiError, getAllPages } from '../../api/client';
import { formatMoney as money } from '../../support/money';
import PosDialog from './PosDialog.vue';

/**
 * Pasar artículos de esta cuenta a otra cuenta abierta de la sucursal (§4.5): «estas dos cervezas van a la cuenta de
 * la barra».
 *
 * ## Qué manda y qué vuelve
 *
 * `POST /pos-accounts/{origen}/move-items` con `target_account_ulid` e `item_ulids`: de 1 a 50 líneas COMPLETAS (el
 * servidor no parte cantidades). Responde con la cuenta de DESTINO, no con ésta: por eso, al terminar, la pantalla vuelve
 * a leer la suya en lugar de usar la respuesta. Las dos cuentas tienen que estar vivas, sin pagos y en la misma sucursal;
 * el movimiento queda historizado con quien lo hizo y la comanda ya enviada no se reimprime.
 *
 * ## Sólo se ofrece lo ya enviado a preparar
 *
 * El servidor cambia la cuenta de la línea pero deja su ORDEN donde estaba. Con una línea comandada da igual —la comanda
 * ya salió—, pero una pendiente por enviar quedaría colgada de una orden de esta cuenta: la otra no podría mandarla a
 * cocina (409, «esa orden no pertenece a la cuenta») y comandar aquí la mandaría a nombre de ésta. Lo pendiente se corrige
 * quitándolo (×) y capturándolo allá, que es un toque; el hueco del servidor queda reportado.
 *
 * ## La mesa de esta cuenta
 *
 * Hoy el servidor libera la mesa de ORIGEN después de mover si no queda OTRA cuenta viva en ella, aunque a ésta le queden
 * artículos (`AccountOperations::moveItems` → `releaseTableIfEmpty`, que se excluye a sí misma de la cuenta). Mientras
 * eso no se corrija, la ventana lo advierte en lugar de prometer que la mesa sigue ocupada.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },

    /** La sucursal activa: de ella salen las cuentas de destino (el servidor rechaza las de otra). */
    branchUlid: { type: String, default: null },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const MAX_LINEAS = 50; // el tope del servidor por movimiento

const seleccion = ref([]);
const destino = ref('');
const cuentas = ref([]);
const cargando = ref(true);
const errorCarga = ref(null);
const filtro = ref('');
const procesando = ref(false);
const error = ref(null);
const idFiltro = useId();
const idDestinos = useId();

/** Lo que se puede pasar: lo ya enviado a preparar que no se canceló. */
const movibles = computed(() => (props.account.items ?? []).filter((i) => i.was_commanded && i.status !== 'cancelled'));

/** Lo capturado sin enviar: no se ofrece (ver arriba), pero se explica. */
const pendientes = computed(() => (props.account.items ?? []).filter((i) => i.status === 'captured'));

const todos = computed(() => movibles.value.length > 0 && seleccion.value.length === movibles.value.length);

// Si la cuenta se volvió a leer (un 409, otra terminal), lo elegido que ya no está deja de estarlo.
watch(movibles, (lista) => {
    seleccion.value = seleccion.value.filter((ulid) => lista.some((i) => i.ulid === ulid));
});

function alternarTodos() {
    seleccion.value = todos.value ? [] : movibles.value.map((i) => i.ulid);
}

onMounted(cargarCuentas);

async function cargarCuentas() {
    cargando.value = true;
    errorCarga.value = null;

    try {
        const filas = await getAllPages('/pos-accounts', { only_open: 1, branch: props.branchUlid });

        cuentas.value = filas
            .filter((c) => c.ulid !== props.account.ulid)
            .sort((a, b) => a.display_name.localeCompare(b.display_name, 'es', { numeric: true }));

        if (! cuentas.value.some((c) => c.ulid === destino.value)) {
            destino.value = '';
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

/** Una cuenta con pagos no recibe artículos (el servidor la rechaza): se ve, deshabilitada y con el porqué. */
const conPagos = (cuenta) => (cuenta.totals?.paid_total ?? '0.00') !== '0.00';

const cuentasVisibles = computed(() => {
    const q = filtro.value.trim().toLowerCase();

    if (q === '') {
        return cuentas.value;
    }

    return cuentas.value.filter((c) => [c.display_name, c.folio, c.waiter?.name]
        .some((texto) => (texto ?? '').toLowerCase().includes(q)));
});

const destinoElegido = computed(() => cuentas.value.find((c) => c.ulid === destino.value) ?? null);

/** ¿Esta cuenta se queda sin nada que cobrar? Pasan todos los enviados y no hay pendientes. */
const quedaVacia = computed(() => seleccion.value.length === movibles.value.length && pendientes.value.length === 0);

const puedeMover = computed(() => seleccion.value.length > 0
    && seleccion.value.length <= MAX_LINEAS
    && destinoElegido.value !== null
    && ! conPagos(destinoElegido.value));

/** Cantidad legible: «2» y no «2.0000» (DECIMAL(12,4) del servidor). */
const cantidad = (valor) => String(parseFloat(valor));

const modificadores = (item) => (item.modifiers ?? [])
    .map((m) => (Number(m.quantity) > 1 ? `${m.name} ×${cantidad(m.quantity)}` : m.name))
    .join(' · ');

async function mover() {
    if (procesando.value || ! puedeMover.value) {
        return;
    }

    procesando.value = true;
    error.value = null;

    const n = seleccion.value.length;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/move-items`, {
            version,
            target_account_ulid: destino.value,
            item_ulids: [...seleccion.value],
        }));

        // La respuesta es la cuenta de DESTINO: la de esta pantalla se vuelve a leer.
        emit('hecho', {
            recargar: true,
            aviso: `${n === 1 ? 'Pasó 1 artículo' : `Pasaron ${n} artículos`} a ${data.display_name}.`,
        });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;

        // Un 409: una de las dos cuentas cambió (la cobraron, le movieron artículos). Se releen las dos cosas.
        if (e.status === 409) {
            emit('refrescar');
            cargarCuentas();
        }
    } finally {
        procesando.value = false;
    }
}
</script>

<template>
    <PosDialog
        titulo="Pasar artículos a otra cuenta"
        ancho="amplio"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="mover"
    >
        <fieldset class="grupo">
            <legend class="grupo__titulo">1. Qué artículos pasan</legend>

            <p v-if="movibles.length === 0" class="nota">No hay artículos enviados que pasar.</p>

            <template v-else>
                <label class="opcion opcion--todos">
                    <input type="checkbox" :checked="todos" :disabled="procesando" @change="alternarTodos" />
                    <span class="opcion__datos"><span class="opcion__nombre">Todos ({{ movibles.length }})</span></span>
                </label>

                <ul class="lista">
                    <li v-for="i in movibles" :key="i.ulid">
                        <label class="opcion" :class="{ 'opcion--elegida': seleccion.includes(i.ulid) }">
                            <input v-model="seleccion" type="checkbox" :value="i.ulid" :disabled="procesando" />
                            <span class="opcion__datos">
                                <span class="opcion__nombre">{{ cantidad(i.quantity) }} × {{ i.article_name }}</span>
                                <span v-if="i.modifiers?.length" class="opcion__detalle">{{ modificadores(i) }}</span>
                                <span class="opcion__detalle">{{ i.status_label }}</span>
                            </span>
                            <span class="opcion__importe">{{ money(i.line_total) }}</span>
                        </label>
                    </li>
                </ul>

                <p class="nota">Cada línea pasa completa, con su cantidad y su importe.</p>
            </template>

            <p v-if="pendientes.length > 0" class="nota">
                Lo pendiente por enviar no se pasa desde aquí: quítalo con × y captúralo en la otra cuenta.
            </p>
        </fieldset>

        <fieldset class="grupo">
            <legend class="grupo__titulo">2. A qué cuenta</legend>

            <p v-if="cargando" class="nota" aria-live="polite">Cargando cuentas abiertas…</p>

            <div v-else-if="errorCarga" class="alert aviso" role="alert">
                {{ errorCarga }}
                <button type="button" class="link-button" @click="cargarCuentas">Reintentar</button>
            </div>

            <p v-else-if="cuentas.length === 0" class="nota">
                No hay otra cuenta abierta en la sucursal. Ábrela desde la lista de cuentas y vuelve aquí.
            </p>

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
                            :class="{ 'opcion--elegida': destino === c.ulid, 'opcion--bloqueada': conPagos(c) }"
                        >
                            <input
                                v-model="destino"
                                type="radio"
                                :name="idDestinos"
                                :value="c.ulid"
                                :disabled="procesando || conPagos(c)"
                            />
                            <span class="opcion__datos">
                                <span class="opcion__nombre">{{ c.display_name }}</span>
                                <span class="opcion__detalle">
                                    {{ c.status_label }}<template v-if="c.waiter"> · {{ c.waiter.name }}</template>
                                    <template v-if="conPagos(c)"> · ya tiene pagos: no recibe artículos</template>
                                </span>
                            </span>
                            <span class="opcion__importe">{{ money(c.totals.total) }}</span>
                        </label>
                    </li>
                    <li v-if="cuentasVisibles.length === 0" class="nota">Ninguna cuenta coincide con la búsqueda.</li>
                </ul>
            </template>
        </fieldset>

        <p v-if="destinoElegido && seleccion.length > 0" class="texto">
            {{ seleccion.length === 1 ? 'Pasa 1 artículo' : `Pasan ${seleccion.length} artículos` }} a
            <strong>{{ destinoElegido.display_name }}</strong>. La comanda ya enviada no se reimprime y el movimiento queda
            registrado a tu nombre.
        </p>

        <template v-if="account.table && seleccion.length > 0">
            <p v-if="quedaVacia" class="nota">
                Esta cuenta se queda sin artículos: la mesa {{ account.table.code }} se libera si no hay otra cuenta abierta
                en ella.
            </p>
            <!-- Lo que HOY hace el servidor al mover una parte (ver arriba). Quitar este aviso cuando se corrija. -->
            <p v-else class="alert alert--notice aviso">
                Ojo: aunque a esta cuenta le queden artículos, el plano marcará la mesa {{ account.table.code }} como libre
                si no hay otra cuenta abierta en ella. Avisa a tu equipo para que nadie la ocupe mientras sigan ahí.
            </p>
        </template>

        <p v-if="seleccion.length > MAX_LINEAS" class="alert aviso" role="alert">
            Se pueden pasar hasta {{ MAX_LINEAS }} líneas por movimiento.
        </p>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                Volver
            </button>
            <button type="submit" class="button" :disabled="procesando || ! puedeMover">
                {{ procesando ? 'Pasando…' : 'Pasar artículos' }}
            </button>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.5; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; }
.aviso { margin: 0; }
.campo { margin: 0; }

.grupo { display: grid; gap: 0.5rem; margin: 0; padding: 0; border: 0; min-width: 0; }
.grupo__titulo { padding: 0; margin-bottom: 0.1rem; font-size: 0.85rem; font-weight: 650; }

.lista { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.4rem; }

/* Cada opción es un renglón táctil entero: se toca en cualquier parte, no sólo en la casilla. */
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
.opcion--todos { border-style: dashed; }
.opcion input { flex: none; width: 1.15rem; height: 1.15rem; margin: 0; accent-color: var(--color-acento); }
.opcion__datos { flex: 1; display: grid; gap: 0.1rem; min-width: 0; }
.opcion__nombre { font-weight: 600; font-size: 0.92rem; overflow-wrap: anywhere; }
.opcion__detalle { font-size: 0.78rem; color: var(--color-suave); }
.opcion__importe { flex: none; font-variant-numeric: tabular-nums; font-size: 0.9rem; }
</style>
