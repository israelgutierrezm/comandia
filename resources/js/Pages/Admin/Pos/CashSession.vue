<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { api, ApiError, getAllPages, orEmptyWhenForbidden } from '../../../api/client';
import { formatInBranchTime } from '../../../support/datetime';
// El formato de dinero compartido por todo el POS (antes esta pantalla insertaba las comas a mano).
import { formatMoney as money } from '../../../support/money';
import { useApiForm } from '../../../stores/useResourceList';
import { pushToast } from '../../../stores/useToasts';
import { useAuthorization } from '../../../composables/useAuthorization';
import ListHeader from '../../../components/ListHeader.vue';
import PinAuthorizationDialog from '../../../components/inventory/PinAuthorizationDialog.vue';
import ExpenseForm from '../../../components/finance/ExpenseForm.vue';

/**
 * La caja: abrir el turno, declarar, retirar, registrar gastos de caja, abrir el cajón, cerrar y ver el corte (§6.3,
 * §6.5).
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
 * navegador. La única cifra que sí es de presentación es la DURACIÓN del turno —un reloj—, que no decide nada.
 */
const session = ref(null);
const cut = ref(null);
const cutForbidden = ref(false);
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

// { permission, reason, retry } del 409: abre el diálogo de PIN. Uno solo para el retiro y el cajón, como en la cuenta:
// cada acción deja anotado en `retry` cómo reintentarse con la firma.
const pendingAuthorization = ref(null);

// Lo que se ofrece depende del ROL ACTIVO (presentación; el servidor vuelve a decidir en cada endpoint).
const { canWrite } = useAuthorization();

// Gasto de caja (§6.5): el cajero paga los garrafones con dinero del cajón, y un arqueo que no conoce esa salida da
// corto sin que nada lo explique. Sólo con el permiso de gasto DESDE caja, que es el que exige la ruta.
const puedeGastar = computed(() => canWrite('finance.expenses.create_from_cash'));
const expenseCategories = ref([]);
const expenseCategoriesLoaded = ref(false);
const expenseLookupError = ref(null);
const cutRefreshError = ref(null);

// Abrir el cajón fuera de un cobro (§6.3): siempre con PIN de un superior, sin umbral. La ruta es de escritura
// (`can.write`), así que un negocio en sólo lectura tampoco lo ve.
const puedeAbrirCajon = computed(() => canWrite('pos.cash_drawer.open'));
const drawerForm = ref({ reason: '' });
const drawerProcesando = ref(false);
const drawerError = ref(null);
const drawerReasonError = ref(null);

// El reloj de la duración avanza solo. Sin esto, «2h 15m» se quedaría clavado hasta la siguiente recarga.
const ahora = ref(Date.now());
let reloj = null;

onMounted(() => {
    load();

    // Aparte de `load()` y una sola vez: el catálogo casi no cambia, y si fallara no debe tumbar la caja entera.
    if (puedeGastar.value) {
        loadExpenseCategories();
    }

    reloj = setInterval(() => { ahora.value = Date.now(); }, 60000);
});

onBeforeUnmount(() => clearInterval(reloj));

async function load() {
    loading.value = true;
    loadError.value = null;
    cutRefreshError.value = null;

    try {
        const [sesiones, terminales, metodos] = await Promise.all([
            // Se piden VARIOS y se elige el de la sucursal activa. Pedir uno solo traía «el primer turno abierto del
            // negocio», que con dos sucursales es el de la otra: en el navegador salió el turno de Polanco bajo Roma
            // Norte, con la misma terminal llamada «Caja 1» y nada en pantalla que lo dijera.
            api.get('/pos-sessions', { status: 'open', per_page: 20 }),
            // Las terminales se piden a la lectura del POS (permiso de abrir turno), no a la de administración: el
            // Cajero no ve la configuración, y pedirla tumbaba toda la caja. Ya vienen sólo activas y de la sucursal
            // activa. (Antes se pedía también `/branches`, que la pantalla nunca usaba: fuera.)
            api.get('/pos/terminals'),
            orEmptyWhenForbidden(api.get('/payment-methods', { status: 'active', per_page: 50 })),
        ]);

        session.value = elegirTurno(sesiones.data);
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

/**
 * Vuelve a pedir el corte después de algo que lo mueve (un gasto de caja). Un fallo aquí se dice junto al corte y no
 * reemplaza la pantalla: la caja sigue operable aunque la cifra no se haya podido actualizar.
 */
async function refreshCut() {
    cutRefreshError.value = null;

    try {
        await loadCut();
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        cutRefreshError.value = e.title;
    }
}

/**
 * Las categorías ACTIVAS: registrar con una inactiva da 422. Todas las páginas —el servidor corta en 100—. Mientras no
 * lleguen no se pinta el formulario: diría «no hay categorías» de algo que todavía no se sabe.
 */
async function loadExpenseCategories() {
    expenseLookupError.value = null;

    try {
        expenseCategories.value = await getAllPages('/expense-categories', { status: 'active' });
        expenseCategoriesLoaded.value = true;
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        expenseLookupError.value = e.title;
    }
}

/** Un gasto de caja se descuenta del efectivo esperado: si el corte está a la vista, se vuelve a pedir. */
async function onExpenseRegistered() {
    if (cut.value) {
        await refreshCut();
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
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message, retry: trySubmitWithdraw };

            return;
        }

        pendingAuthorization.value = null;
        withdrawError.value = e.message;
    } finally {
        withdrawProcesando.value = false;
    }
}

/** La terminal del turno, tal como la sirve `/pos/terminals`: con su impresora, que es la que tiene el cajón. */
const terminalDelTurno = computed(
    () => terminals.value.find((t) => t.ulid === session.value?.terminal?.ulid) ?? null,
);

const impresoraDelCajon = computed(() => terminalDelTurno.value?.printer ?? null);

/**
 * Abre el cajón: SIEMPRE exige el PIN de un superior (§6.3), sin umbral. Sin token la primera vez —el motivo se valida
 * antes que la firma, así que un motivo vacío se corrige sin gastar un PIN—; el 409 abre el diálogo y con la firma se
 * reintenta la MISMA apertura.
 *
 * Lo que el servidor devuelve es un trabajo de impresión EN COLA: el cajón lo abre el agente de impresión al recibirlo.
 * Por eso el aviso dice «orden enviada» y no «cajón abierto».
 */
async function trySubmitDrawer(authorizationToken = null) {
    const impresora = impresoraDelCajon.value;

    if (! impresora || drawerProcesando.value) {
        return;
    }

    drawerProcesando.value = true;
    drawerError.value = null;
    drawerReasonError.value = null;

    const cuerpo = { reason: drawerForm.value.reason.trim() };

    if (authorizationToken) {
        cuerpo.authorization_token = authorizationToken;
    }

    try {
        await api.post(`/printers/${impresora.ulid}/open-drawer`, cuerpo);

        drawerForm.value = { reason: '' };
        pendingAuthorization.value = null;
        pushToast(`Orden enviada: el cajón de «${impresora.name}» se abre en cuanto el agente de impresión la reciba.`);
    } catch (e) {
        if (! (e instanceof ApiError)) {
            throw e;
        }

        if (e.isAuthorizationRequired) {
            pendingAuthorization.value = { permission: e.requiredPermission, reason: e.message, retry: trySubmitDrawer };

            return;
        }

        // Cualquier otro fallo cierra el PIN para que el aviso se lea en la tarjeta, no detrás del diálogo.
        pendingAuthorization.value = null;

        if (e.isValidation && e.fieldErrors.reason) {
            drawerReasonError.value = e.fieldErrors.reason;
        } else {
            // 409 de una impresora sin cajón, 403 sin permiso: mensajes escritos para quien opera.
            drawerError.value = e.isValidation ? (Object.values(e.fieldErrors)[0] ?? e.message) : e.message;
        }
    } finally {
        drawerProcesando.value = false;
    }
}

// El diálogo de PIN es uno para todas las acciones sensibles de la caja: reintenta la que dejó la firma pendiente.
const onGranted = (token) => pendingAuthorization.value?.retry?.(token);

const close = useApiForm(async () => {
    await api.post(`/pos-sessions/${session.value.ulid}/close`);
    await load();
});

/**
 * Cerrar el turno no tiene vuelta atrás: no existe «reabrir caja», y la diferencia contra lo declarado se asienta en el
 * diario financiero, que es inmutable. Un toque de más en la tablet no debe bastar para eso: se confirma diciendo qué
 * pasa. Mientras corre, el botón queda deshabilitado (`close.processing`).
 */
async function cerrarTurno() {
    if (!window.confirm(`¿Cerrar el turno ${session.value.folio}? El corte queda definitivo: la diferencia contra lo declarado se asienta en el diario financiero y el turno ya no admite cobros ni retiros.`)) {
        return;
    }

    await close.submit();
}

const isOpen = computed(() => session.value?.status === 'open');

/** Los métodos que el corte muestra, con lo declarado y la diferencia. */
const cutRows = computed(() => cut.value?.by_method ?? []);

/** Duración del turno como reloj (sólo presentación): «2h 15m». */
const duracion = computed(() => {
    if (! session.value?.opened_at) {
        return null;
    }

    const ms = ahora.value - new Date(session.value.opened_at).getTime();

    if (ms < 0) {
        return '0m';
    }

    const minutos = Math.floor(ms / 60000);
    const horas = Math.floor(minutos / 60);
    const resto = minutos % 60;

    return horas > 0 ? `${horas}h ${resto}m` : `${resto}m`;
});

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

        <div v-else-if="loadError" class="alert" role="alert">{{ loadError.title }}</div>

        <!-- Sin turno abierto: lo único que se puede hacer es abrir uno. -->
        <section v-else-if="!session" class="tarjeta abrir">
            <header class="tarjeta__cab">
                <span class="tarjeta__icono">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <rect x="3" y="8" width="18" height="12" rx="2" /><path stroke-linecap="round" d="M3 8l3-4h12l3 4M7 12h4" />
                    </svg>
                </span>
                <h2>Abrir caja</h2>
            </header>

            <p class="nota">
                Sin caja abierta no se cobra, no se descuenta y no se registran gastos de caja. Las cuentas sí se pueden
                abrir y capturar: el mesero toma la orden antes de que llegue el cajero.
            </p>

            <form class="formulario" @submit.prevent="open.submit()">
                <label class="field">
                    <span class="field__label">Terminal</span>
                    <select v-model="openForm.terminal_ulid" class="input" required>
                        <option value="">Elige…</option>
                        <!--
                            La sucursal va en la etiqueta, no de adorno: el nombre de la terminal es único por SUCURSAL,
                            no por negocio, así que dos «Caja 1» son lo normal en cuanto hay dos sucursales. Sólo si viene:
                            `/pos/terminals` ya las filtra a la sucursal activa y hoy no la incluye, y sin esta guarda la
                            opción se leía «Caja 1 — », con un guion colgando.
                        -->
                        <option v-for="t in terminals" :key="t.ulid" :value="t.ulid">
                            {{ t.name }}<template v-if="t.branch?.name"> — {{ t.branch.name }}</template>
                        </option>
                    </select>
                    <!-- `fieldErrors` ya trae el PRIMER mensaje de cada campo como texto: indexarlo con `[0]` pintaba
                         sólo su primera letra. -->
                    <span v-if="open.fieldErrors.value.terminal_ulid" class="field__error">
                        {{ open.fieldErrors.value.terminal_ulid }}
                    </span>
                </label>

                <label class="field">
                    <span class="field__label">Fondo de apertura</span>
                    <input v-model="openForm.opening_float" class="input" type="text" inputmode="decimal" placeholder="0.00" required />
                    <span v-if="open.fieldErrors.value.opening_float" class="field__error">
                        {{ open.fieldErrors.value.opening_float }}
                    </span>
                </label>

                <p v-if="open.generalError.value" class="alert" role="alert">{{ open.generalError.value }}</p>

                <div class="acciones">
                    <button type="submit" class="button" :disabled="open.processing.value">Abrir caja</button>
                </div>
            </form>
        </section>

        <template v-else>
            <!-- EL TURNO: la cabecera del estado, con la acción de cierre a la derecha (como la referencia). -->
            <section class="tarjeta turno">
                <div class="turno__top">
                    <h2 class="turno__folio">Turno {{ session.folio }}</h2>

                    <div class="turno__acciones">
                        <button
                            type="button"
                            class="button button--danger"
                            :disabled="close.processing.value || !isOpen"
                            @click="cerrarTurno"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="5" y="11" width="14" height="9" rx="2" /><path stroke-linecap="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                            </svg>
                            Cerrar turno
                        </button>
                    </div>
                </div>

                <dl class="datos">
                    <div>
                        <dt>Estado</dt>
                        <dd><span class="estado" :class="isOpen ? 'estado--abierta' : 'estado--cerrada'">{{ session.status_label }}</span></dd>
                    </div>
                    <div><dt>Terminal</dt><dd>{{ session.terminal?.name }} — {{ session.branch?.name }}</dd></div>
                    <div><dt>Fondo inicial</dt><dd class="cifra">{{ money(session.opening_float) }}</dd></div>
                    <div><dt>Apertura</dt><dd>{{ fecha(session.opened_at) }}</dd></div>
                    <div><dt>Duración</dt><dd class="duracion">{{ duracion ?? '—' }}</dd></div>
                </dl>

                <p v-if="close.generalError.value" class="alert" role="alert">{{ close.generalError.value }}</p>
                <p class="turno__pie nota">
                    Cerrar exige haber declarado el cierre. La diferencia entre lo declarado y lo esperado se asienta en
                    el diario financiero, con nombre, monto y actor.
                </p>
            </section>

            <!-- RESUMEN + CORTE: ambos salen del corte (permiso finance.cuts.view), del diario y nunca sumados aquí
                 (§6.9). Por eso viven bajo el mismo v-if: quien no puede ver el corte tampoco ve el resumen. -->
            <div v-if="cut" class="rejilla">
                <section class="tarjeta resumen-card">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
                            </svg>
                        </span>
                        <h2>Resumen del turno</h2>
                    </header>

                    <dl class="resumen">
                        <div class="resumen__grid">
                            <div class="resumen__par"><dt>Ventas del turno</dt><dd>{{ money(cut.sales_total) }}</dd></div>
                            <div v-for="p in cut.payments_by_method" :key="p.method_ulid" class="resumen__par">
                                <dt>{{ p.method }}</dt><dd>{{ money(p.amount) }}</dd>
                            </div>
                            <div class="resumen__par"><dt>Gastos</dt><dd>{{ money(cut.expenses_total) }}</dd></div>
                            <div class="resumen__par"><dt>Retiros</dt><dd>{{ money(cut.withdrawals_total) }}</dd></div>
                            <div class="resumen__par"><dt>Fondo inicial</dt><dd>{{ money(session.opening_float) }}</dd></div>
                        </div>

                        <!-- Anclado al pie: cuando la tarjeta se estira para igualar al corte, el hueco queda ARRIBA del
                             recuadro, no debajo. -->
                        <div class="resumen__par resumen__par--destacado">
                            <dt>Efectivo teórico</dt><dd>{{ money(cut.expected_cash) }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- EL CORTE: el arqueo, esperado contra declarado, método por método. -->
                <section class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="6" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><path stroke-linecap="round" d="M20 4L8.5 15.5M20 20L8.5 8.5" />
                            </svg>
                        </span>
                        <h2>Corte actual</h2>
                    </header>

                    <p class="nota">
                    Se calcula del diario financiero, nunca se almacena. El efectivo esperado incluye el fondo, los
                    cobros, los cambios, los retiros, los gastos desde caja, las propinas liquidadas y los abonos de crédito.
                </p>

                <div class="tabla-scroll">
                    <table class="corte">
                        <thead>
                            <tr>
                                <th>Método</th>
                                <th class="num">Esperado</th>
                                <th class="num">Declarado</th>
                                <th class="num">Diferencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="fila in cutRows" :key="fila.method_ulid">
                                <td>{{ fila.method }}</td>
                                <td class="num">{{ money(fila.expected) }}</td>
                                <!-- Sin declarar se pinta «—» y no «$0.00»: son dos cosas distintas. -->
                                <td class="num">{{ money(fila.declared) }}</td>
                                <td class="num" :class="{ falta: fila.difference && fila.difference.startsWith('-') }">
                                    {{ money(fila.difference) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="cutRows.length">
                            <tr>
                                <th>Totales</th>
                                <th class="num efectivo-teorico">{{ money(cut.expected_cash) }} <small>efvo. teórico</small></th>
                                <th class="num">{{ money(cut.total_declared) }}</th>
                                <th class="num" :class="{ falta: cut.total_difference && cut.total_difference.startsWith('-') }">
                                    {{ money(cut.total_difference) }}
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </section>
            </div>

            <p v-else-if="cutForbidden" class="tarjeta nota nota--sola">
                No tienes permiso para ver el corte. Puedes declarar lo que contaste: el precorte es <strong>ciego</strong>
                a propósito — se cuenta sin ver el esperado.
            </p>

            <p v-if="cutRefreshError" class="alert" role="alert">
                No se pudo actualizar el corte después del gasto: {{ cutRefreshError }}
            </p>

            <div class="rejilla">
                <section class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="4" y="3" width="16" height="18" rx="2" /><path stroke-linecap="round" d="M8 8h8M8 12h8M8 16h5" />
                            </svg>
                        </span>
                        <h2>Declarar conteo</h2>
                    </header>

                    <form class="formulario" @submit.prevent="declare.submit()">
                        <label class="field">
                            <span class="field__label">Tipo de conteo</span>
                            <select v-model="declareForm.moment" class="input">
                                <option value="precount">Precorte</option>
                                <option value="close">Cierre</option>
                            </select>
                        </label>

                        <div class="montos">
                            <label v-for="m in methods" :key="m.ulid" class="field">
                                <span class="field__label">{{ m.name }}</span>
                                <input
                                    v-model="declareForm.amounts[m.ulid]"
                                    class="input"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                />
                            </label>
                        </div>

                        <p v-if="declare.generalError.value" class="alert" role="alert">{{ declare.generalError.value }}</p>

                        <div class="acciones">
                            <button type="submit" class="button" :disabled="declare.processing.value || !isOpen">
                                Declarar
                            </button>
                        </div>
                    </form>
                </section>

                <section class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14" />
                            </svg>
                        </span>
                        <h2>Retiro parcial</h2>
                    </header>

                    <p class="nota">
                        Todo retiro exige el PIN de un superior, sin importar el monto: es dinero saliendo del cajón
                        durante el servicio.
                    </p>

                    <form class="formulario" @submit.prevent="trySubmitWithdraw()">
                        <label class="field">
                            <span class="field__label">Monto</span>
                            <input v-model="withdrawForm.amount" class="input" type="text" inputmode="decimal" placeholder="0.00" required />
                        </label>

                        <label class="field">
                            <span class="field__label">Motivo</span>
                            <input v-model="withdrawForm.reason" class="input" type="text" placeholder="Ej. Pago a proveedor" required />
                        </label>

                        <p v-if="withdrawError" class="alert" role="alert">{{ withdrawError }}</p>

                        <div class="acciones">
                            <button type="submit" class="button" :disabled="withdrawProcesando || !isOpen">
                                Retirar
                            </button>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Lo que saca dinero del cajón fuera de un cobro: el gasto de caja y la apertura del cajón. Cada tarjeta
                 sólo con su permiso; sin ninguno de los dos, la fila no existe. -->
            <div v-if="isOpen && (puedeGastar || puedeAbrirCajon)" class="rejilla">
                <section v-if="puedeGastar" class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2ZM9 8h6M9 12h6" />
                            </svg>
                        </span>
                        <h2>Gasto de caja</h2>
                    </header>

                    <p class="nota">
                        Lo que se paga con el efectivo del cajón —los garrafones, el hielo— se registra aquí: sale del turno
                        abierto de esta sucursal y se descuenta del efectivo esperado. Sin registrarlo, el arqueo da corto
                        sin que nada lo explique.
                    </p>

                    <p v-if="expenseLookupError" class="alert" role="alert">
                        No se pudieron cargar las categorías de gasto, así que por ahora no se puede registrar uno.
                        Detalle: {{ expenseLookupError }}
                    </p>

                    <p v-else-if="! expenseCategoriesLoaded" class="nota">Cargando categorías…</p>

                    <ExpenseForm
                        v-else
                        id-prefix="caja-gasto"
                        :branch-ulid="session.branch?.ulid ?? ''"
                        source="cash_session"
                        :categories="expenseCategories"
                        @registered="onExpenseRegistered"
                    />
                </section>

                <section v-if="puedeAbrirCajon" class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <rect x="3" y="11" width="18" height="9" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M3 11l2.5-6h13L21 11M10 15.5h4" />
                            </svg>
                        </span>
                        <h2>Abrir cajón</h2>
                    </header>

                    <p class="nota">
                        Abrir el cajón fuera de un cobro exige siempre el PIN de un superior, sin importar para qué: queda
                        registrado quién lo autorizó, cuándo y por qué.
                    </p>

                    <!-- El cajón se abre mandando una orden a la impresora de la terminal. Sin impresora no hay por dónde
                         abrirlo, y ofrecer el botón haría pedir un PIN para nada. -->
                    <p v-if="! impresoraDelCajon" class="alert alert--notice" role="status">
                        <template v-if="terminalDelTurno">
                            La terminal «{{ terminalDelTurno.name }}» no tiene impresora asignada, y el cajón se abre a través
                            de ella.
                        </template>
                        <template v-else>
                            No se encontró la terminal de este turno entre las activas de la sucursal.
                        </template>
                        Asígnale una impresora con cajón en Organización › Terminales.
                    </p>

                    <!-- Con impresora pero sin cajón conectado: pedir el PIN sería gastar la firma de un superior para nada. -->
                    <p v-else-if="impresoraDelCajon.supports_cash_drawer === false" class="alert alert--notice" role="status">
                        La impresora «{{ impresoraDelCajon.name }}» de esta terminal no tiene cajón de dinero. Márcalo en
                        Organización › Impresoras si sí lo tiene, o asigna a la terminal la impresora del cajón.
                    </p>

                    <form v-else class="formulario" @submit.prevent="trySubmitDrawer()">
                        <div class="field">
                            <label class="field__label" for="cajon-motivo">Motivo</label>
                            <input
                                id="cajon-motivo"
                                v-model="drawerForm.reason"
                                class="input"
                                type="text"
                                minlength="3"
                                maxlength="200"
                                autocomplete="off"
                                placeholder="Ej. Cambio de billetes para el fondo"
                                required
                                :aria-invalid="drawerReasonError ? 'true' : undefined"
                                :aria-describedby="drawerReasonError ? 'cajon-motivo-error' : 'cajon-motivo-ayuda'"
                            />
                            <span v-if="drawerReasonError" id="cajon-motivo-error" class="field__error">{{ drawerReasonError }}</span>
                            <span id="cajon-motivo-ayuda" class="field__hint">
                                Se abre el de la impresora «{{ impresoraDelCajon.name }}», la de esta terminal.
                            </span>
                        </div>

                        <p v-if="drawerError" class="alert" role="alert">{{ drawerError }}</p>

                        <div class="acciones">
                            <button type="submit" class="button" :disabled="drawerProcesando || !isOpen">
                                {{ drawerProcesando ? 'Enviando…' : 'Abrir cajón' }}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </template>

        <!-- El PIN de un superior para el retiro y el cajón: mismo diálogo que las demás acciones sensibles (ADR-008), y
             donde vive el teclado en pantalla. El 409 `authorization_required` lo abre; con la firma se reintenta la
             misma operación que lo pidió (`pendingAuthorization.retry`). El gasto de caja trae el suyo en su
             componente, porque su firma depende de un umbral y no siempre aparece. -->
        <PinAuthorizationDialog
            v-if="pendingAuthorization"
            :required-permission="pendingAuthorization.permission"
            :reason="pendingAuthorization.reason"
            @granted="onGranted"
            @cancelled="pendingAuthorization = null"
        />
    </div>
</template>

<style scoped>
/* Botones, campos y avisos compartidos del admin (`.button`, `.field`, `.alert`): antes esta pantalla llevaba copias
   propias (`.btn`, `.campo`, `.alerta`) que se iban separando del resto. */
@import '../../../../css/admin-page.css';

.caja { display: grid; gap: 1.25rem; }

/* Tarjetas: el mismo lenguaje de superficie del resto del admin. */
.tarjeta {
    background: var(--color-superficie);
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-lg);
    box-shadow: var(--sombra-sm);
    padding: 1.15rem 1.25rem;
}
.tarjeta__cab { display: flex; align-items: center; gap: 0.7rem; margin-bottom: 0.9rem; }
.tarjeta__cab h2 { margin: 0; font-size: 1.05rem; font-weight: 650; }
.tarjeta__icono {
    flex: none;
    display: grid;
    place-items: center;
    width: 2.4rem;
    height: 2.4rem;
    border-radius: var(--radio-sm);
    background: color-mix(in srgb, var(--color-acento) 12%, transparent);
    color: var(--color-acento);
}
.tarjeta__icono svg { width: 1.3rem; height: 1.3rem; }

/* El turno: tarjeta con acento verde a la izquierda; es el estado «en curso» del salón. */
.turno { border-left: 4px solid var(--color-exito); }
.turno__top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
.turno__folio { margin: 0; font-size: 1.15rem; font-weight: 700; }
.turno__acciones { display: flex; gap: 0.6rem; }
.turno__pie { margin: 0.9rem 0 0; }

.datos {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: 1rem 1.5rem;
    margin: 0;
    padding-top: 0.9rem;
    border-top: 1px solid var(--color-borde);
}
.datos dt { font-size: 0.78rem; color: var(--color-suave); margin-bottom: 0.15rem; }
.datos dd { margin: 0; font-weight: 600; }
.cifra { font-variant-numeric: tabular-nums; }
.duracion { color: var(--color-exito); font-variant-numeric: tabular-nums; }

.estado { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; font-weight: 600; }
.estado::before { content: ''; width: 0.5rem; height: 0.5rem; border-radius: 50%; background: currentColor; }
.estado--abierta { color: var(--color-exito); }
.estado--cerrada { color: var(--color-suave); }

/* Resumen del turno: pares etiqueta/cifra en dos columnas, con el efectivo teórico anclado al pie. */
.resumen-card { display: flex; flex-direction: column; }
.resumen { display: flex; flex-direction: column; flex: 1; margin: 0; }
.resumen__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.15rem 1.5rem; }
.resumen__par {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.45rem 0;
    border-bottom: 1px solid color-mix(in srgb, var(--color-borde) 55%, transparent);
}
.resumen__par dt { color: var(--color-suave); font-size: 0.85rem; }
.resumen__par dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
.resumen__par--destacado {
    /* `margin-top: auto` lo empuja al fondo cuando la tarjeta se estira para igualar al corte. */
    margin-top: auto;
    padding: 0.6rem 0.8rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    border-radius: var(--radio-sm);
    background: color-mix(in srgb, var(--color-acento) 8%, transparent);
}
.resumen__par--destacado dt,
.resumen__par--destacado dd { color: var(--color-acento); }
@media (max-width: 32rem) { .resumen__grid { grid-template-columns: 1fr; } }

/* Rejilla de dos columnas, colapsable. `stretch` iguala la altura de las dos tarjetas de cada fila (la más corta crece
   hasta la más alta) en vez de que cada una quede a la altura de su contenido. */
.rejilla { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; align-items: stretch; }
@media (max-width: 60rem) { .rejilla { grid-template-columns: 1fr; } }

.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0 0 0.9rem; }
.nota--sola { margin: 0; }

/* Formularios */
.formulario { display: grid; gap: 0.85rem; }
.montos { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.75rem; }
/* `.field` y `.alert` traen margen inferior pensado para formularios en bloque; éstos son rejillas y el `gap` ya separa.
   Sin esto, margen y hueco se sumarían. */
.formulario .field,
.formulario .alert { margin: 0; }
.acciones { display: flex; justify-content: flex-end; }

/* Tabla del corte */
.tabla-scroll { overflow-x: auto; }
.corte { width: 100%; border-collapse: collapse; }
.corte th, .corte td { text-align: left; padding: 0.55rem 0.65rem; border-bottom: 1px solid var(--color-borde); }
.corte thead th { font-size: 0.75rem; font-weight: 600; color: var(--color-suave); text-transform: uppercase; letter-spacing: 0.03em; }
.corte .num { text-align: right; font-variant-numeric: tabular-nums; }
.corte tfoot th { font-weight: 700; border-top: 2px solid var(--color-borde); border-bottom: none; }
.corte tfoot small { display: block; font-size: 0.68rem; font-weight: 400; color: var(--color-suave); text-transform: none; letter-spacing: 0; }
.efectivo-teorico { color: var(--color-acento); }
.falta { color: var(--color-peligro); font-weight: 600; }
</style>
