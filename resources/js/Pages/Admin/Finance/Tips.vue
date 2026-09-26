<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { pushToast } from '../../../stores/useToasts';
import { useAuthorization } from '../../../composables/useAuthorization';
import { formatMoney } from '../../../support/money';
import ListHeader from '../../../components/ListHeader.vue';
import Icon from '../../../components/Icon.vue';

/**
 * Liquidación de propinas (§6.6, D39).
 *
 * ## «A quién le debo», no «quién ha tenido propinas»
 *
 * El servidor lista sólo a quien tiene saldo pendiente, y lo CALCULA del diario: la propina cobrada y atribuida a cada
 * persona menos lo que ya se le entregó (D283). Aquí no se suma ni se resta nada: la cifra que se ve es la del servidor,
 * y después de liquidar se pinta la que él devuelve.
 *
 * ## Sale del cajón
 *
 * La propina se entrega en efectivo de la caja: exige un turno abierto en la sucursal elegida y se descuenta en su
 * arqueo. Sin registrarla, el arqueo da corto todas las noches por una cantidad que nadie explica, y una diferencia que
 * siempre está deja de mirarse.
 *
 * ## Se puede entregar una parte
 *
 * El monto se propone completo y se puede bajar. Que no pase de lo pendiente lo comprueba el servidor DENTRO de la
 * transacción: otra caja pudo liquidar algo entre que esta lista se cargó y se apretó el botón.
 */
const page = usePage();
const { canWrite } = useAuthorization();
const puedeLiquidar = computed(() => canWrite('finance.tips.settle'));

const pendientes = ref([]);
const cargando = ref(true);
const errorCarga = ref(null);

const sucursales = ref([]);
const sucursal = ref('');
const errorSucursales = ref(null);

/** Lo que se va a entregar a cada persona, por ULID de su membresía (texto tal como se teclea). */
const montos = ref({});

/** El error de cada fila, por ULID: el que falla es un renglón, no la pantalla. */
const errores = ref({});

/** La persona a la que se le está liquidando: bloquea los botones para no entregar dos veces. */
const liquidando = ref(null);

onMounted(() => Promise.all([cargar(), cargarSucursales()]));

async function cargar() {
    cargando.value = true;
    errorCarga.value = null;

    try {
        const { data } = await api.get('/tip-settlements/pending');

        pendientes.value = data ?? [];
        montos.value = Object.fromEntries(pendientes.value.map((p) => [p.membership.ulid, p.available]));
        errores.value = {};
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorCarga.value = e.isForbidden ? 'No tienes permiso para liquidar propinas.' : e.message;
    } finally {
        cargando.value = false;
    }
}

/** De qué cajón sale el efectivo: las sucursales del alcance, empezando por la activa. */
async function cargarSucursales() {
    errorSucursales.value = null;

    try {
        const { data } = await api.get('/context');
        sucursales.value = data?.branches ?? [];

        const activa = page.props.context?.branch_ulid;
        sucursal.value = sucursales.value.some((b) => b.ulid === activa) ? activa : (sucursales.value[0]?.ulid ?? '');
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        errorSucursales.value = e.title;
    }
}

const nombreSucursal = computed(() => sucursales.value.find((b) => b.ulid === sucursal.value)?.name ?? '');

/** ¿La cifra del servidor es cero? Se compara la CADENA, sin pasar el dinero por un flotante. */
function esCero(valor) {
    return /^0*(\.0*)?$/.test(String(valor ?? '').trim());
}

function montoLegible(valor) {
    const formateado = formatMoney(valor);

    return formateado === '—' ? String(valor ?? '').trim() : formateado;
}

async function liquidar(pendiente) {
    const ulid = pendiente.membership.ulid;
    const monto = String(montos.value[ulid] ?? '').trim();

    if (liquidando.value !== null) {
        return;
    }

    if (! sucursal.value) {
        errores.value = { ...errores.value, [ulid]: 'Elige de qué sucursal sale el efectivo.' };

        return;
    }

    const texto = `¿Entregar ${montoLegible(monto)} de propina a ${pendiente.membership.name}? Sale en efectivo del `
        + `cajón del turno abierto de ${nombreSucursal.value} y se descuenta en su arqueo. Queda asentado en el diario `
        + 'financiero y no se puede deshacer.';

    if (! window.confirm(texto)) {
        return;
    }

    liquidando.value = ulid;
    errores.value = { ...errores.value, [ulid]: null };

    try {
        const { data } = await api.post('/tip-settlements', {
            membership_ulid: ulid,
            branch_ulid: sucursal.value,
            amount: monto,
        });

        pushToast(`Propina de ${formatMoney(data.amount)} entregada a ${pendiente.membership.name}.`);

        // Lo que le queda por cobrar, según el servidor. Si ya no se le debe nada, sale de la lista: es «a quién le
        // debo», y quien está al corriente no pertenece a ella.
        if (esCero(data.remaining)) {
            pendientes.value = pendientes.value.filter((p) => p.membership.ulid !== ulid);
        } else {
            pendientes.value = pendientes.value.map(
                (p) => (p.membership.ulid === ulid ? { ...p, available: data.remaining } : p),
            );
            montos.value = { ...montos.value, [ulid]: data.remaining };
        }
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // «No hay caja abierta» y «sólo hay X pendientes» llegan como 422 del módulo (bajo `finance`): se muestran en el
        // renglón, tal cual. Si el pendiente cambió, el propio mensaje pide recargar.
        const mensaje = e.isValidation
            ? (e.fieldErrors.finance ?? e.fieldErrors.amount ?? Object.values(e.fieldErrors)[0] ?? e.message)
            : e.message;

        errores.value = { ...errores.value, [ulid]: mensaje };
    } finally {
        liquidando.value = null;
    }
}
</script>

<template>
    <Head title="Propinas" />

    <div class="propinas animar-entrada">
        <ListHeader
            title="Propinas por liquidar"
            subtitle="A quién se le debe propina y cuánto, calculado del diario: lo cobrado a su nombre menos lo ya entregado. Se entrega en efectivo del cajón y se descuenta en su arqueo."
            :count="cargando || errorCarga ? null : pendientes.length"
        >
            <template #action>
                <button type="button" class="button button--neutral" :disabled="cargando || liquidando !== null" @click="cargar">
                    <Icon name="refresh" /> Actualizar
                </button>
            </template>
        </ListHeader>

        <section v-if="puedeLiquidar" class="tarjeta bloque">
            <div class="field">
                <label class="field__label" for="propina-sucursal">¿De qué caja sale el efectivo?</label>
                <select
                    id="propina-sucursal"
                    v-model="sucursal"
                    class="input"
                    :disabled="! sucursales.length"
                    aria-describedby="propina-sucursal-ayuda"
                >
                    <option value="" disabled>{{ sucursales.length ? 'Elige sucursal…' : 'Sin sucursales' }}</option>
                    <option v-for="b in sucursales" :key="b.ulid" :value="b.ulid">{{ b.name }}</option>
                </select>
                <span id="propina-sucursal-ayuda" class="field__hint">
                    Sale del turno abierto de esa sucursal: sin caja abierta no se puede liquidar. Lo pendiente es de cada
                    persona, sin importar en qué sucursal cobró.
                </span>
            </div>

            <p v-if="errorSucursales" class="alert" role="alert">
                No se pudieron cargar tus sucursales, así que por ahora no se puede liquidar. Detalle: {{ errorSucursales }}
            </p>
        </section>

        <p v-if="errorCarga" class="alert" role="alert">{{ errorCarga }}</p>

        <template v-else-if="cargando"></template>

        <p v-else-if="! pendientes.length" class="tarjeta vacio">
            No hay propinas pendientes: todo el personal está al corriente.
        </p>

        <ul v-else class="lista">
            <li v-for="p in pendientes" :key="p.membership.ulid" class="tarjeta persona">
                <div class="persona__cabecera">
                    <div class="persona__nombre">
                        <strong>{{ p.membership.name }}</strong>
                        <span v-if="p.membership.employee_code" class="muted">{{ p.membership.employee_code }}</span>
                    </div>
                    <div class="persona__pendiente">
                        <span class="muted">Pendiente</span>
                        <strong>{{ formatMoney(p.available) }}</strong>
                    </div>
                </div>

                <form v-if="puedeLiquidar" class="persona__form" @submit.prevent="liquidar(p)">
                    <div class="persona__fila">
                        <div class="field">
                            <label class="field__label" :for="`propina-monto-${p.membership.ulid}`">A entregar</label>
                            <input
                                :id="`propina-monto-${p.membership.ulid}`"
                                v-model="montos[p.membership.ulid]"
                                class="input"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                required
                                :aria-describedby="`propina-ayuda-${p.membership.ulid}`"
                            />
                        </div>

                        <button
                            type="submit"
                            class="button"
                            :disabled="liquidando !== null || ! sucursal"
                        >
                            <Icon name="check" /> {{ liquidando === p.membership.ulid ? 'Entregando…' : 'Liquidar' }}
                        </button>
                    </div>

                    <span :id="`propina-ayuda-${p.membership.ulid}`" class="field__hint">
                        Puedes entregar una parte; el resto queda pendiente.
                    </span>
                </form>

                <p v-if="errores[p.membership.ulid]" class="alert persona__error" role="alert">
                    {{ errores[p.membership.ulid] }}
                </p>
            </li>
        </ul>
    </div>
</template>

<style scoped>
@import '../../../../css/admin-page.css';

.propinas {
    display: grid;
    gap: 1rem;
    min-width: 0;
}

.propinas > .alert {
    margin: 0;
}

.bloque {
    display: grid;
    gap: 0.75rem;
    padding: 1.15rem;
    max-width: 36rem;
}

.bloque .field,
.bloque .alert {
    margin: 0;
}

.vacio {
    margin: 0;
    padding: 1.5rem 1.25rem;
    text-align: center;
    color: var(--color-suave);
}

.lista {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 20rem), 1fr));
    gap: 0.75rem;
    align-items: start;
}

.persona {
    display: grid;
    gap: 0.75rem;
    padding: 1rem 1.1rem;
}

.persona__cabecera {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.persona__nombre {
    display: grid;
    gap: 0.1rem;
    min-width: 0;
}

.persona__pendiente {
    display: grid;
    justify-items: end;
    gap: 0.1rem;
}

.persona__pendiente strong {
    font-size: 1.15rem;
    font-variant-numeric: tabular-nums;
    color: var(--color-acento);
}

.persona__form {
    display: grid;
}

/* El botón se alinea con el campo y no con la pista de abajo: la pista va fuera de esta fila. */
.persona__fila {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.75rem;
}

.persona__fila .field {
    flex: 1 1 10rem;
    margin: 0;
}

.persona__error {
    margin: 0;
}

.muted {
    font-size: 0.8rem;
    color: var(--color-suave);
}
</style>
