<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { api, ApiError, getAllPages } from '../../api/client';
import PosDialog from './PosDialog.vue';

/**
 * Cambiar la cuenta de mesa, o asignarle una si venía de barra (§6.4): «nos pasamos a la del fondo».
 *
 * `POST /pos-accounts/{cuenta}/table` con `table_ulid`. El servidor ocupa primero la mesa nueva y después libera la que se
 * deja (si no le queda otra cuenta viva), exige que sean de la misma sucursal y que la cuenta admita artículos, y responde
 * con la cuenta ya en su mesa nueva. A una cuenta de barra le quita la etiqueta: desde ahí se llama «Mesa X».
 *
 * Las mesas salen de `GET /restaurant-tables?available_only=1&branch=…`, que pide `floor.layouts.view` además del
 * permiso de la operación (`floor.tables.join`): la opción sólo se ofrece a quien tiene los dos. Se filtran también por
 * `is_available`, que el servidor resuelve: `available_only` no descarta las mesas retiradas del piso, y ofrecer una sería
 * ofrecer un 409.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },

    /** La sucursal activa: sólo sus mesas (el servidor rechaza una de otra sucursal). */
    branchUlid: { type: String, default: null },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const mesas = ref([]);
const cargando = ref(true);
const errorCarga = ref(null);
const elegidaUlid = ref('');
const filtro = ref('');
const procesando = ref(false);
const error = ref(null);
const idFiltro = useId();
const idMesas = useId();

onMounted(cargarMesas);

async function cargarMesas() {
    cargando.value = true;
    errorCarga.value = null;

    try {
        const filas = await getAllPages('/restaurant-tables', { available_only: 1, branch: props.branchUlid });

        mesas.value = filas
            .filter((m) => m.is_available && m.ulid !== props.account.table?.ulid)
            .sort((a, b) => String(a.code).localeCompare(String(b.code), 'es', { numeric: true }));

        if (! mesas.value.some((m) => m.ulid === elegidaUlid.value)) {
            elegidaUlid.value = '';
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

const mesasVisibles = computed(() => {
    const q = filtro.value.trim().toLowerCase();

    if (q === '') {
        return mesas.value;
    }

    return mesas.value.filter((m) => [m.code, m.name, m.zone?.name]
        .some((texto) => String(texto ?? '').toLowerCase().includes(q)));
});

const elegida = computed(() => mesas.value.find((m) => m.ulid === elegidaUlid.value) ?? null);

/** ¿Ya salió algo a cocina? Entonces hay papeles impresos con la mesa de antes. */
const hayComandas = computed(() => (props.account.items ?? []).some((i) => i.was_commanded));

const lugares = (mesa) => {
    const n = mesa.effective_seats ?? mesa.seats;

    return n === 1 ? '1 lugar' : `${n} lugares`;
};

async function mover() {
    if (procesando.value || ! elegida.value) {
        return;
    }

    procesando.value = true;
    error.value = null;

    const mesa = elegida.value;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/table`, {
            version,
            table_ulid: mesa.ulid,
        }));

        emit('hecho', { cuenta: data, aviso: `La cuenta pasó a la mesa ${mesa.code}.` });
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;

        // Un 409: la mesa se ocupó mientras se elegía, o la cuenta cambió. Se releen las mesas y la cuenta.
        if (e.status === 409) {
            emit('refrescar');
            cargarMesas();
        }
    } finally {
        procesando.value = false;
    }
}
</script>

<template>
    <PosDialog
        :titulo="account.table ? 'Cambiar de mesa' : 'Asignar mesa'"
        ancho="amplio"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="mover"
    >
        <p class="texto">
            <template v-if="account.table">
                <strong>{{ account.display_name }}</strong> está en la mesa {{ account.table.code }}. Elige la mesa libre a
                la que se pasa.
            </template>
            <template v-else>
                <strong>{{ account.display_name }}</strong> no tiene mesa. Elige la mesa libre donde se sienta.
            </template>
        </p>

        <p v-if="cargando" class="nota" aria-live="polite">Cargando mesas libres…</p>

        <div v-else-if="errorCarga" class="alert aviso" role="alert">
            {{ errorCarga }}
            <button type="button" class="link-button" @click="cargarMesas">Reintentar</button>
        </div>

        <p v-else-if="mesas.length === 0" class="nota">No hay mesas libres en esta sucursal ahora mismo.</p>

        <fieldset v-else class="grupo">
            <legend class="grupo__titulo">Mesas libres</legend>

            <div v-if="mesas.length > 12" class="field campo">
                <label :for="idFiltro" class="field__label">Buscar mesa</label>
                <input
                    :id="idFiltro"
                    v-model="filtro"
                    type="search"
                    class="input"
                    placeholder="Número, nombre o zona"
                    autocomplete="off"
                    @keydown.enter.prevent
                />
            </div>

            <ul class="mesas">
                <li v-for="m in mesasVisibles" :key="m.ulid">
                    <label class="mesa" :class="{ 'mesa--elegida': elegidaUlid === m.ulid }">
                        <input v-model="elegidaUlid" type="radio" :name="idMesas" :value="m.ulid" :disabled="procesando" />
                        <span class="mesa__codigo">{{ m.code }}</span>
                        <span v-if="m.name" class="mesa__detalle">{{ m.name }}</span>
                        <span class="mesa__detalle">{{ lugares(m) }}<template v-if="m.zone"> · {{ m.zone.name }}</template></span>
                    </label>
                </li>
                <li v-if="mesasVisibles.length === 0" class="nota">Ninguna mesa coincide con la búsqueda.</li>
            </ul>
        </fieldset>

        <template v-if="elegida">
            <p v-if="account.table" class="texto">
                La cuenta pasa a la mesa <strong>{{ elegida.code }}</strong>, que queda ocupada, y la mesa
                {{ account.table.code }} se libera si no le queda otra cuenta abierta.
                <template v-if="hayComandas">Las comandas ya impresas siguen diciendo mesa {{ account.table.code }}.</template>
            </p>
            <p v-else class="texto">
                «{{ account.display_name }}» pasa a llamarse <strong>Mesa {{ elegida.code }}</strong> y la mesa queda ocupada.
            </p>
        </template>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                Volver
            </button>
            <button type="submit" class="button" :disabled="procesando || ! elegida">
                <template v-if="procesando">Moviendo…</template>
                <template v-else-if="elegida">Pasar a la mesa {{ elegida.code }}</template>
                <template v-else>Elige una mesa</template>
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

/* Las mesas como tarjetas táctiles: el número grande, que es como se buscan en el salón. */
.mesas {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(6.5rem, 1fr));
    gap: 0.5rem;
}
.mesa {
    position: relative;
    display: grid;
    gap: 0.1rem;
    min-height: 4.25rem;
    padding: 0.6rem 0.7rem 0.6rem 2.1rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio);
    background: var(--color-superficie);
    cursor: pointer;
    transition: border-color 0.15s ease, background-color 0.15s ease;
}
.mesa:hover { border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde)); }
.mesa--elegida { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 10%, transparent); }
.mesa input {
    position: absolute;
    top: 0.75rem;
    left: 0.65rem;
    width: 1.05rem;
    height: 1.05rem;
    margin: 0;
    accent-color: var(--color-acento);
}
.mesa__codigo { font-size: 1.15rem; font-weight: 700; line-height: 1.2; overflow-wrap: anywhere; }
.mesa--elegida .mesa__codigo { color: var(--color-acento); }
.mesa__detalle { font-size: 0.76rem; color: var(--color-suave); }
</style>
