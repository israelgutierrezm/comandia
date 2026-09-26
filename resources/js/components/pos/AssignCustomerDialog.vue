<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { api, ApiError } from '../../api/client';
import { useAuthorization } from '../../composables/useAuthorization';
import Icon from '../Icon.vue';
import PosDialog from './PosDialog.vue';

/**
 * Asignar un cliente a la cuenta (D43): para cobrarla a crédito, facturarla con sus datos fiscales y que el consumo quede
 * en su historial.
 *
 * - Buscar: `GET /customers?search=…` busca por nombre y teléfono (la lista blanca del listado) y se piden sólo los
 *   activos. Desde 2 caracteres y con una pausa corta, para no mandar una petición por tecla.
 * - Asignar: `POST /pos-accounts/{cuenta}/customer` con `customer_ulid`, que es OBLIGATORIO: el servidor no admite dejar
 *   la cuenta sin cliente, así que aquí no se ofrece «quitar». Sólo mientras la cuenta admite artículos; si ya tenía
 *   cliente, lo reemplaza. Devuelve la cuenta.
 * - Alta exprés: sólo si el rol puede administrar clientes (`customers.customers.manage`, el permiso de
 *   `POST /customers`). Lo único que el servidor exige es el nombre (el teléfono es opcional y único): se da de alta y se
 *   asigna enseguida.
 *
 * La cuenta que devuelve el servidor todavía no publica QUIÉN es su cliente, así que la ventana no puede mostrar el
 * actual: lo confirma el aviso al asignar.
 */
const props = defineProps({
    account: { type: Object, required: true },

    /** La escritura, por la cola de la pantalla: recibe `(version) => promesa` y la ejecuta con la versión vigente. */
    escribir: { type: Function, required: true },
});

const emit = defineEmits(['cerrar', 'hecho', 'refrescar']);

const { canWrite } = useAuthorization();
const puedeDarDeAlta = computed(() => canWrite('customers.customers.manage'));

const modo = ref('buscar'); // 'buscar' | 'alta'
const busqueda = ref('');
const resultados = ref([]);
const buscando = ref(false);
const buscado = ref(false); // ya se buscó este texto (para poder decir «ninguno coincide»)
const errorBusqueda = ref(null);
const elegido = ref(null);
const alta = ref({ name: '', phone: '' });
const erroresAlta = ref({});
const procesando = ref(false);
const error = ref(null);
const campoBusqueda = ref(null);
const campoNombre = ref(null);
const idBusqueda = useId();
const idClientes = useId();
const idNombre = useId();
const idTelefono = useId();

const MIN_BUSQUEDA = 2;

onMounted(() => campoBusqueda.value?.focus());

let pausa = null;
let turno = 0;

watch(busqueda, (texto) => {
    clearTimeout(pausa);

    const q = texto.trim();

    if (q.length < MIN_BUSQUEDA) {
        turno++; // una respuesta en vuelo ya no corresponde a lo escrito
        resultados.value = elegido.value ? [elegido.value] : [];
        buscado.value = false;
        buscando.value = false;
        errorBusqueda.value = null;

        return;
    }

    pausa = setTimeout(() => buscar(q), 300);
});

onBeforeUnmount(() => clearTimeout(pausa));

/** Enter en el buscador busca ya, sin esperar la pausa (y sin enviar el formulario). */
function buscarYa() {
    const q = busqueda.value.trim();

    clearTimeout(pausa);

    if (q.length >= MIN_BUSQUEDA) {
        buscar(q);
    }
}

async function buscar(q) {
    const mio = ++turno;

    buscando.value = true;
    errorBusqueda.value = null;

    try {
        const { data } = await api.get('/customers', { search: q, status: 'active', per_page: 20 });

        if (mio === turno) {
            resultados.value = data;
            buscado.value = true;
        }
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (mio === turno) {
            errorBusqueda.value = e.message;
        }
    } finally {
        if (mio === turno) {
            buscando.value = false;
        }
    }
}

/** Pasar al alta con lo que ya se tecleó: si parece teléfono va al teléfono, si no, al nombre. */
async function irAAlta() {
    const q = busqueda.value.trim();

    alta.value = /^[\d\s+()-]+$/.test(q) ? { name: '', phone: q } : { name: q, phone: '' };
    erroresAlta.value = {};
    error.value = null;
    modo.value = 'alta';

    await nextTick();
    campoNombre.value?.focus();
}

async function asignar(cliente) {
    procesando.value = true;
    error.value = null;

    try {
        const { data } = await props.escribir((version) => api.post(`/pos-accounts/${props.account.ulid}/customer`, {
            version,
            customer_ulid: cliente.ulid,
        }));

        emit('hecho', { cuenta: data, aviso: `Cliente asignado: ${cliente.name}.` });

        return true;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        error.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;

        if (e.status === 409) {
            emit('refrescar');
        }

        return false;
    } finally {
        procesando.value = false;
    }
}

async function darDeAltaYAsignar() {
    if (alta.value.name.trim().length < 2) {
        return;
    }

    procesando.value = true;
    erroresAlta.value = {};
    error.value = null;

    let cliente = null;

    try {
        const { data } = await api.post('/customers', {
            name: alta.value.name.trim(),
            phone: alta.value.phone.trim() || null,
        });

        cliente = data;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (e.isValidation) {
            erroresAlta.value = e.fieldErrors;
        } else {
            error.value = e.message;
        }

        procesando.value = false;

        return;
    }

    // El cliente ya existe. Si asignarlo falla, queda elegido en la búsqueda para reintentar con un toque, sin darlo
    // de alta otra vez (el teléfono repetido lo rechazaría).
    modo.value = 'buscar';
    elegido.value = cliente;
    resultados.value = [cliente];

    if (! await asignar(cliente)) {
        error.value = `${cliente.name} ya quedó dado de alta, pero no se asignó a la cuenta: ${error.value}`;
    }
}

function enviar() {
    if (procesando.value) {
        return undefined;
    }

    if (modo.value === 'alta') {
        return darDeAltaYAsignar();
    }

    return elegido.value ? asignar(elegido.value) : undefined;
}

const detalleCliente = (c) => [c.phone, c.code].filter(Boolean).join(' · ');
</script>

<template>
    <PosDialog
        titulo="Asignar cliente"
        formulario
        :bloqueada="procesando"
        @cerrar="emit('cerrar')"
        @enviar="enviar"
    >
        <p class="nota">
            Sirve para cobrar la cuenta a crédito, facturarla con los datos del cliente y que quede en su historial de
            consumos. Si ya tenía un cliente, lo reemplaza: se puede cambiar mientras la cuenta esté abierta, pero no dejarla
            sin cliente.
        </p>

        <template v-if="modo === 'buscar'">
            <div class="field campo">
                <label :for="idBusqueda" class="field__label">Buscar por nombre o teléfono</label>
                <input
                    :id="idBusqueda"
                    ref="campoBusqueda"
                    v-model="busqueda"
                    type="search"
                    class="input"
                    autocomplete="off"
                    placeholder="Ej.: Ana López o 55 1234 5678"
                    @keydown.enter.prevent="buscarYa"
                />
            </div>

            <p v-if="buscando" class="nota" aria-live="polite">Buscando…</p>
            <p v-else-if="errorBusqueda" class="alert aviso" role="alert">{{ errorBusqueda }}</p>
            <p v-else-if="buscado && resultados.length === 0" class="nota">
                Ningún cliente activo coincide con «{{ busqueda.trim() }}».
            </p>

            <fieldset v-if="resultados.length > 0" class="grupo">
                <legend class="grupo__titulo">Clientes</legend>
                <ul class="lista">
                    <li v-for="c in resultados" :key="c.ulid">
                        <label class="opcion" :class="{ 'opcion--elegida': elegido?.ulid === c.ulid }">
                            <input
                                type="radio"
                                :name="idClientes"
                                :value="c.ulid"
                                :checked="elegido?.ulid === c.ulid"
                                :disabled="procesando"
                                @change="elegido = c"
                            />
                            <span class="opcion__datos">
                                <span class="opcion__nombre">{{ c.name }}</span>
                                <span v-if="detalleCliente(c)" class="opcion__detalle">{{ detalleCliente(c) }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
            </fieldset>

            <button v-if="puedeDarDeAlta" type="button" class="link-button alta" :disabled="procesando" @click="irAAlta">
                <Icon name="plus" :size="14" /> ¿No está? Darlo de alta
            </button>
        </template>

        <template v-else>
            <p class="texto">Alta exprés: sólo el nombre es obligatorio. El resto del expediente se llena después en Clientes.</p>

            <div class="field campo">
                <label :for="idNombre" class="field__label">Nombre</label>
                <input
                    :id="idNombre"
                    ref="campoNombre"
                    v-model="alta.name"
                    class="input"
                    :class="{ 'input--error': erroresAlta.name }"
                    autocomplete="off"
                    minlength="2"
                    maxlength="120"
                    required
                    :aria-invalid="erroresAlta.name ? 'true' : 'false'"
                />
                <span v-if="erroresAlta.name" class="field__error" role="alert">{{ erroresAlta.name }}</span>
            </div>

            <div class="field campo">
                <label :for="idTelefono" class="field__label">Teléfono (opcional)</label>
                <input
                    :id="idTelefono"
                    v-model="alta.phone"
                    type="tel"
                    inputmode="tel"
                    class="input"
                    :class="{ 'input--error': erroresAlta.phone }"
                    autocomplete="off"
                    maxlength="20"
                    :aria-invalid="erroresAlta.phone ? 'true' : 'false'"
                />
                <span v-if="erroresAlta.phone" class="field__error" role="alert">{{ erroresAlta.phone }}</span>
            </div>
        </template>

        <p v-if="error" class="alert aviso" role="alert">{{ error }}</p>

        <template #acciones>
            <template v-if="modo === 'buscar'">
                <button type="button" class="button button--neutral" :disabled="procesando" @click="emit('cerrar')">
                    Volver
                </button>
                <button type="submit" class="button" :disabled="procesando || ! elegido">
                    <template v-if="procesando">Asignando…</template>
                    <template v-else-if="elegido">Asignar a {{ elegido.name }}</template>
                    <template v-else>Elige un cliente</template>
                </button>
            </template>
            <template v-else>
                <button type="button" class="button button--neutral" :disabled="procesando" @click="modo = 'buscar'">
                    Volver a buscar
                </button>
                <button type="submit" class="button" :disabled="procesando || alta.name.trim().length < 2">
                    {{ procesando ? 'Guardando…' : 'Dar de alta y asignar' }}
                </button>
            </template>
        </template>
    </PosDialog>
</template>

<style scoped>
@import '../../../css/admin-page.css';

.texto { margin: 0; line-height: 1.5; }
.nota { margin: 0; color: var(--color-suave); font-size: 0.85rem; line-height: 1.45; }
.aviso { margin: 0; }
.campo { margin: 0; }
.alta { justify-self: start; min-height: 2.5rem; }

.grupo { display: grid; gap: 0.5rem; margin: 0; padding: 0; border: 0; min-width: 0; }
.grupo__titulo { padding: 0; margin-bottom: 0.1rem; font-size: 0.85rem; font-weight: 650; }

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
.opcion:hover { border-color: color-mix(in srgb, var(--color-acento) 45%, var(--color-borde)); }
.opcion--elegida { border-color: var(--color-acento); background: color-mix(in srgb, var(--color-acento) 8%, transparent); }
.opcion input { flex: none; width: 1.15rem; height: 1.15rem; margin: 0; accent-color: var(--color-acento); }
.opcion__datos { flex: 1; display: grid; gap: 0.1rem; min-width: 0; }
.opcion__nombre { font-weight: 600; font-size: 0.92rem; overflow-wrap: anywhere; }
.opcion__detalle { font-size: 0.78rem; color: var(--color-suave); }
</style>
