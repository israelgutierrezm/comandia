<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { useApiForm } from '../../../stores/useResourceList';

/**
 * La caja: abrir el turno, declarar, retirar, cerrar y ver el corte (§6.3, §6.5).
 *
 * ## Es una pantalla de TURNO, no un listado
 *
 * Casi todo el shell son listas con filtros. Ésta no: quien la abre tiene una sola pregunta —«¿cómo va mi caja?»— y
 * como mucho dos turnos que le importen, el suyo y el que va a abrir. Ponerle un buscador y paginación sería tratar
 * una operación como un catálogo.
 *
 * ## El corte se pide APARTE de la sesión, y se puede no tener permiso de verlo
 *
 * Ahí vive el precorte ciego (D289): declarar y ver el esperado son permisos distintos, así que quien cuenta el efectivo
 * puede no poder ver contra qué. Esta pantalla lo trata como un caso normal y no como un error — si el corte responde
 * 403, se declara igual y no se muestra el bloque.
 *
 * ## Y NO calcula nada
 *
 * Ni el esperado, ni la diferencia, ni lo que falta. Todo viene del servidor (§6.9). Sumar aquí daría un número que
 * podría no coincidir con el del corte, y el cajero creería que le falta dinero cuando lo que falla es la resta del
 * navegador.
 */
const session = ref(null);
const cut = ref(null);
const cutForbidden = ref(false);
const branches = ref([]);
const terminals = ref([]);
const methods = ref([]);
const loading = ref(true);
const loadError = ref(null);

const openForm = ref({ terminal_ulid: '', opening_float: '' });
const declareForm = ref({ moment: 'close', amounts: {} });
const withdrawForm = ref({ amount: '', reason: '', authorization_token: '' });

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const [sesiones, sucursales, terminales, metodos] = await Promise.all([
            api.get('/pos-sessions', { status: 'open', per_page: 1 }),
            api.get('/branches', { status: 'active', per_page: 50 }),
            api.get('/terminals', { status: 'active', per_page: 50 }),
            api.get('/payment-methods', { status: 'active', per_page: 50 }),
        ]);

        session.value = sesiones.data[0] ?? null;
        branches.value = sucursales.data;
        terminals.value = terminales.data;
        methods.value = metodos.data;

        if (session.value) {
            await loadCut();
        }
    } catch (e) {
        if (e instanceof ApiError) {
            loadError.value = e;
        } else {
            throw e;
        }
    } finally {
        loading.value = false;
    }
}

/**
 * El corte, si esta persona puede verlo.
 *
 * Un 403 aquí NO es un error: es el precorte ciego funcionando. Se distingue de un fallo de verdad para no pintar un
 * recuadro rojo a quien está haciendo exactamente lo que debe.
 */
async function loadCut() {
    cutForbidden.value = false;

    try {
        const respuesta = await api.get(`/pos-sessions/${session.value.ulid}/cut`);
        cut.value = respuesta.data;
    } catch (e) {
        if (e instanceof ApiError && e.status === 403) {
            cutForbidden.value = true;
            cut.value = null;

            return;
        }

        throw e;
    }
}

const open = useApiForm(async () => {
    await api.post('/pos-sessions', openForm.value);
    await load();
});

const declare = useApiForm(async () => {
    // Los montos se mandan como lista, que es lo que el endpoint espera. El formulario los tiene indexados por ULID
    // porque un `v-model` por método es lo natural en la pantalla; la conversión va aquí y no en el servidor.
    const declarations = Object.entries(declareForm.value.amounts)
        .filter(([, monto]) => monto !== '' && monto !== null)
        .map(([payment_method_ulid, declared_amount]) => ({ payment_method_ulid, declared_amount }));

    await api.post(`/pos-sessions/${session.value.ulid}/declarations`, {
        moment: declareForm.value.moment,
        declarations,
    });

    await load();
});

const withdraw = useApiForm(async () => {
    await api.post(`/pos-sessions/${session.value.ulid}/withdrawals`, withdrawForm.value);

    withdrawForm.value = { amount: '', reason: '', authorization_token: '' };

    await load();
});

const close = useApiForm(async () => {
    await api.post(`/pos-sessions/${session.value.ulid}/close`);
    await load();
});

const isOpen = computed(() => session.value?.status === 'open');

/** Los métodos que el corte muestra, con lo declarado y la diferencia. */
const cutRows = computed(() => cut.value?.by_method ?? []);

function money(value) {
    return value === null || value === undefined ? '—' : `$${value}`;
}
</script>

<template>
    <Head title="Caja" />

    <div class="caja">
        <h1>Caja</h1>

        <p v-if="loading">Cargando…</p>

        <div v-else-if="loadError" class="error">{{ loadError.title }}</div>

        <!-- Sin turno abierto: lo único que se puede hacer es abrir uno. -->
        <section v-else-if="!session" class="panel">
            <h2>Abrir caja</h2>

            <p class="nota">
                Sin caja abierta no se cobra, no se descuenta y no se registran gastos de caja. Las cuentas sí se pueden
                abrir y capturar: el mesero toma la orden antes de que llegue el cajero.
            </p>

            <form @submit.prevent="open.submit()">
                <label>
                    Terminal
                    <select v-model="openForm.terminal_ulid" required>
                        <option value="">Elige…</option>
                        <option v-for="t in terminals" :key="t.ulid" :value="t.ulid">{{ t.name }}</option>
                    </select>
                </label>
                <p v-if="open.fieldErrors.value.terminal_ulid" class="campo-error">
                    {{ open.fieldErrors.value.terminal_ulid[0] }}
                </p>

                <label>
                    Fondo de apertura
                    <input v-model="openForm.opening_float" type="text" inputmode="decimal" placeholder="0.00" required />
                </label>
                <p v-if="open.fieldErrors.value.opening_float" class="campo-error">
                    {{ open.fieldErrors.value.opening_float[0] }}
                </p>

                <p v-if="open.generalError.value" class="error">{{ open.generalError.value }}</p>

                <button type="submit" :disabled="open.processing.value">Abrir caja</button>
            </form>
        </section>

        <template v-else>
            <section class="panel">
                <h2>Turno {{ session.folio }}</h2>

                <dl class="datos">
                    <div><dt>Estado</dt><dd>{{ session.status_label }}</dd></div>
                    <div><dt>Terminal</dt><dd>{{ session.terminal?.name }}</dd></div>
                    <div><dt>Fondo</dt><dd>{{ money(session.opening_float) }}</dd></div>
                    <div><dt>Abierta</dt><dd>{{ session.opened_at }}</dd></div>
                </dl>
            </section>

            <!-- EL CORTE. Sólo para quien puede verlo: ahí vive el precorte ciego. -->
            <section v-if="cut" class="panel">
                <h2>Corte</h2>

                <p class="nota">
                    Se calcula del diario financiero, nunca se almacena. El efectivo esperado incluye el fondo, los
                    cobros, los cambios, los retiros, los gastos desde caja, las propinas liquidadas y los abonos de
                    crédito.
                </p>

                <table>
                    <thead>
                        <tr><th>Método</th><th>Esperado</th><th>Declarado</th><th>Diferencia</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="fila in cutRows" :key="fila.method_ulid">
                            <td>{{ fila.method }}</td>
                            <td>{{ money(fila.expected) }}</td>
                            <!-- Sin declarar se pinta «—» y no «$0.00»: son dos cosas distintas. -->
                            <td>{{ money(fila.declared) }}</td>
                            <td :class="{ falta: fila.difference && fila.difference.startsWith('-') }">
                                {{ money(fila.difference) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <p v-else-if="cutForbidden" class="nota">
                No tienes permiso para ver el corte. Puedes declarar lo que contaste: el precorte es <strong>ciego</strong>
                a propósito — se cuenta sin ver el esperado.
            </p>

            <section class="panel">
                <h2>Declarar</h2>

                <form @submit.prevent="declare.submit()">
                    <label>
                        Momento
                        <select v-model="declareForm.moment">
                            <option value="precount">Precorte</option>
                            <option value="close">Cierre</option>
                        </select>
                    </label>

                    <label v-for="m in methods" :key="m.ulid">
                        {{ m.name }}
                        <input
                            v-model="declareForm.amounts[m.ulid]"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                        />
                    </label>

                    <p v-if="declare.generalError.value" class="error">{{ declare.generalError.value }}</p>

                    <button type="submit" :disabled="declare.processing.value || !isOpen">Declarar</button>
                </form>
            </section>

            <section class="panel">
                <h2>Retiro parcial</h2>

                <p class="nota">
                    Todo retiro exige el PIN de un superior, sin importar el monto: es dinero saliendo del cajón durante
                    el servicio.
                </p>

                <form @submit.prevent="withdraw.submit()">
                    <label>
                        Monto
                        <input v-model="withdrawForm.amount" type="text" inputmode="decimal" placeholder="0.00" required />
                    </label>

                    <label>
                        Motivo
                        <input v-model="withdrawForm.reason" type="text" required />
                    </label>

                    <label>
                        Token de autorización
                        <input v-model="withdrawForm.authorization_token" type="text" />
                    </label>

                    <p v-if="withdraw.generalError.value" class="error">{{ withdraw.generalError.value }}</p>

                    <button type="submit" :disabled="withdraw.processing.value || !isOpen">Retirar</button>
                </form>
            </section>

            <section class="panel">
                <h2>Cerrar</h2>

                <p class="nota">
                    Cerrar exige haber declarado. La diferencia entre lo declarado y lo esperado se asienta como
                    movimiento en el diario: queda con nombre, monto y actor.
                </p>

                <p v-if="close.generalError.value" class="error">{{ close.generalError.value }}</p>

                <button type="button" :disabled="close.processing.value || !isOpen" @click="close.submit()">
                    Cerrar caja
                </button>
            </section>
        </template>
    </div>
</template>

<style scoped>
.caja { display: grid; gap: 1.5rem; max-width: 60rem; }
.panel { border: 1px solid #d6d6d6; border-radius: 6px; padding: 1rem 1.25rem; }
.panel h2 { margin-top: 0; }
.nota { color: #555; font-size: 0.9rem; }
.error { color: #a11; }
.campo-error { color: #a11; font-size: 0.85rem; margin: 0.15rem 0 0.5rem; }
.falta { color: #a11; font-weight: 600; }
form { display: grid; gap: 0.5rem; max-width: 24rem; }
label { display: grid; gap: 0.2rem; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.35rem 0.5rem; border-bottom: 1px solid #eee; }
.datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; }
.datos dt { font-size: 0.8rem; color: #666; }
.datos dd { margin: 0; font-weight: 600; }
</style>
