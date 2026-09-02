<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
import { useApiForm } from '../../../stores/useResourceList';
import ListHeader from '../../../components/ListHeader.vue';
import PinAuthorizationDialog from '../../../components/inventory/PinAuthorizationDialog.vue';

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

const page = usePage();

/**
 * La sucursal activa, que decide QUÉ turno es el mío y en qué hora se leen las fechas.
 *
 * OJO con la forma: el contexto que Inertia comparte NO es el que sirve `/api/v1/context`. El de la API anida
 * `active_branch: { ulid, name, timezone }`; el de Inertia trae las llaves planas `branch_ulid`, `branch_name` y
 * `branch_timezone`. Escribí esto leyendo el recurso de la API y en pantalla no falló: `?.` devolvía `undefined`, la
 * selección se caía al primer turno abierto del negocio y salía el de la otra sucursal. Una forma equivocada aquí no
 * revienta, elige mal — que es peor.
 */
const activeBranch = computed(() => {
    const contexto = page.props.context;

    return contexto?.branch_ulid
        ? { ulid: contexto.branch_ulid, name: contexto.branch_name, timezone: contexto.branch_timezone }
        : null;
});

const openForm = ref({ terminal_ulid: '', opening_float: '' });
const declareForm = ref({ moment: 'close', amounts: {} });
const withdrawForm = ref({ amount: '', reason: '' });
const withdrawProcesando = ref(false);
const withdrawError = ref(null);
const pendingAuthorization = ref(null); // { permission, reason } del 409; abre el diálogo de PIN del retiro

onMounted(load);

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const [sesiones, sucursales, terminales, metodos] = await Promise.all([
            // Se piden VARIOS y se elige el de la sucursal activa. Pedir uno solo traía «el primer turno abierto del
            // negocio», que con dos sucursales es el de la otra: en el navegador salió el turno de Polanco bajo Roma
            // Norte, con la misma terminal llamada «Caja 1» y nada en pantalla que lo dijera.
            api.get('/pos-sessions', { status: 'open', per_page: 20 }),
            api.get('/branches', { status: 'active', per_page: 50 }),
            api.get('/terminals', { status: 'active', per_page: 50 }),
            api.get('/payment-methods', { status: 'active', per_page: 50 }),
        ]);

        session.value = elegirTurno(sesiones.data);
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
 * El turno de la sucursal activa.
 *
 * Sin sucursal activa no hay con qué elegir y se toma el primero, que es lo que había antes: es un caso de una sola
 * sucursal, donde la ambigüedad no existe.
 */
function elegirTurno(abiertos) {
    const sucursal = activeBranch.value?.ulid;

    if (! sucursal) {
        return abiertos[0] ?? null;
    }

    return abiertos.find((t) => t.branch?.ulid === sucursal) ?? null;
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

/**
 * Retiro parcial: SIEMPRE exige el PIN de un superior (§6.3), sin importar el monto. Sin token la primera vez; el 409
 * abre el diálogo de PIN y con la firma se reintenta el mismo retiro. El cajero da el PIN de un superior, no teclea un
 * token —no lo tiene.
 */
async function trySubmitWithdraw(authorizationToken = null) {
    withdrawProcesando.value = true;
    withdrawError.value = null;

    const cuerpo = { ...withdrawForm.value };

    if (authorizationToken) {
        cuerpo.authorization_token = authorizationToken;
    }

    try {
        await api.post(`/pos-sessions/${session.value.ulid}/withdrawals`, cuerpo);

        withdrawForm.value = { amount: '', reason: '' };
        pendingAuthorization.value = null;
        await load();
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        // No es un error: es la firma que el retiro siempre pide. El 409 trae el permiso; el diálogo de PIN reintenta
        // este mismo retiro.
        if (e.isAuthorizationRequired) {
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message };

            return;
        }

        pendingAuthorization.value = null;
        withdrawError.value = e.message;
    } finally {
        withdrawProcesando.value = false;
    }
}

const onWithdrawGranted = (token) => trySubmitWithdraw(token);

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

/** La hora de la SUCURSAL. El navegador puede estar en otra zona, y en un corte la hora decide la jornada. */
function fecha(iso) {
    return formatInBranchTime(iso, activeBranch.value?.timezone) || '—';
}
</script>

<template>
    <Head title="Caja" />

    <div class="caja">
        <ListHeader
            title="Caja"
            subtitle="Sin caja abierta no se cobra, no se descuenta y no se registran gastos: aquí se abre el turno y se hace el corte."
        />

        <template v-if="loading"></template>

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
                        <!--
                            La sucursal va en la etiqueta, no de adorno: el nombre de la terminal es único por SUCURSAL,
                            no por negocio, así que dos «Caja 1» son lo normal en cuanto hay dos sucursales. Sin ese dato
                            las dos opciones se ven idénticas y abrir el turno en la equivocada manda las ventas, el
                            corte y los asientos a la sucursal que no es.
                        -->
                        <option v-for="t in terminals" :key="t.ulid" :value="t.ulid">
                            {{ t.name }} — {{ t.branch?.name }}
                        </option>
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
            <div class="caja__abierta">
            <section class="panel">
                <h2>Turno {{ session.folio }}</h2>

                <dl class="datos">
                    <div><dt>Estado</dt><dd>{{ session.status_label }}</dd></div>
                    <!-- La sucursal se nombra: dos terminales de sucursales distintas se llaman igual, y «Caja 1» a
                         secas no dice de cuál turno se está hablando. -->
                    <div><dt>Terminal</dt><dd>{{ session.terminal?.name }} — {{ session.branch?.name }}</dd></div>
                    <div><dt>Fondo</dt><dd>{{ money(session.opening_float) }}</dd></div>
                    <div><dt>Abierta</dt><dd>{{ fecha(session.opened_at) }}</dd></div>
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
                        Tipo de conteo
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

                <form @submit.prevent="trySubmitWithdraw()">
                    <label>
                        Monto
                        <input v-model="withdrawForm.amount" type="text" inputmode="decimal" placeholder="0.00" required />
                    </label>

                    <label>
                        Motivo
                        <input v-model="withdrawForm.reason" type="text" required />
                    </label>

                    <p v-if="withdrawError" class="error">{{ withdrawError }}</p>

                    <button type="submit" :disabled="withdrawProcesando || !isOpen">Retirar</button>
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
            </div>
        </template>

        <!-- El PIN de un superior para el retiro: mismo diálogo que las demás acciones sensibles (ADR-008). El 409
             `authorization_required` lo abre; con la firma se reintenta el mismo retiro. -->
        <PinAuthorizationDialog
            v-if="pendingAuthorization"
            :required-permission="pendingAuthorization.permission"
            :reason="pendingAuthorization.reason"
            @granted="onWithdrawGranted"
            @cancelled="pendingAuthorization = null"
        />
    </div>
</template>

<style scoped>
.caja { display: grid; gap: 1.5rem; }

/* Turno abierto: los paneles (turno, corte, declarar, retiro, cerrar) en dos columnas para aprovechar el ancho y no
   quedar en una tira larga. El corte —la tabla— cae a un lado de los formularios. Colapsa en pantallas angostas. */
.caja__abierta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; align-items: start; }
@media (max-width: 60rem) { .caja__abierta { grid-template-columns: 1fr; } }

.panel {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: 0.75rem;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06);
    padding: 1.15rem 1.25rem;
}
.panel h2 { margin-top: 0; font-size: 1.05rem; font-weight: 650; }
.nota { color: var(--color-suave); font-size: 0.9rem; }
.error { color: var(--color-peligro); }
.campo-error { color: var(--color-peligro); font-size: 0.85rem; margin: 0.15rem 0 0.5rem; }
.falta { color: var(--color-peligro); font-weight: 600; }

form { display: grid; gap: 0.85rem; max-width: 24rem; }
label { display: grid; gap: 0.3rem; font-size: 0.85rem; }

input[type="text"],
select {
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde);
    border-radius: 0.5rem;
    background: var(--color-superficie);
    color: var(--color-contenido);
}

/* Todas las acciones de caja (abrir, declarar, retirar, cerrar) son principales y táctiles. */
form button,
.panel > button {
    font: inherit;
    font-size: 0.95rem;
    font-weight: 600;
    padding: 0.65rem 1.25rem;
    border: 1px solid transparent;
    border-radius: 0.5rem;
    background: var(--color-acento);
    color: var(--color-acento-texto);
    box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
    cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
form button:hover:not(:disabled),
.panel > button:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
form button:disabled,
.panel > button:disabled { opacity: 0.55; cursor: not-allowed; }

table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--color-borde); }
th { font-size: 0.78rem; font-weight: 600; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }
.datos { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 0.75rem; }
.datos dt { font-size: 0.8rem; color: var(--color-suave); }
.datos dd { margin: 0; font-weight: 600; }

@media (prefers-reduced-motion: reduce) {
    form button:hover:not(:disabled),
    .panel > button:hover:not(:disabled) { transform: none; }
}
</style>
