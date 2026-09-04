<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
 * navegador. La única cifra que sí es de presentación es la DURACIÓN del turno —un reloj—, que no decide nada.
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

// El reloj de la duración avanza solo. Sin esto, «2h 15m» se quedaría clavado hasta la siguiente recarga.
const ahora = ref(Date.now());
let reloj = null;

onMounted(() => {
    load();
    reloj = setInterval(() => { ahora.value = Date.now(); }, 60000);
});

onBeforeUnmount(() => clearInterval(reloj));

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

function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    // Separador de miles SIN pasar por Number: el dinero no se opera en JS (D134). El valor ya llega del servidor con
    // dos decimales; aquí sólo se le insertan las comas al entero, como cadena.
    const [entero, decimales = '00'] = String(value).split('.');
    const signo = entero.startsWith('-') ? '-' : '';
    const conMiles = (signo ? entero.slice(1) : entero).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${signo}$${conMiles}.${decimales}`;
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

        <div v-else-if="loadError" class="alerta alerta--error">{{ loadError.title }}</div>

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
                <label class="campo">
                    <span class="campo__etq">Terminal</span>
                    <select v-model="openForm.terminal_ulid" required>
                        <option value="">Elige…</option>
                        <!--
                            La sucursal va en la etiqueta, no de adorno: el nombre de la terminal es único por SUCURSAL,
                            no por negocio, así que dos «Caja 1» son lo normal en cuanto hay dos sucursales.
                        -->
                        <option v-for="t in terminals" :key="t.ulid" :value="t.ulid">
                            {{ t.name }} — {{ t.branch?.name }}
                        </option>
                    </select>
                    <span v-if="open.fieldErrors.value.terminal_ulid" class="campo-error">
                        {{ open.fieldErrors.value.terminal_ulid[0] }}
                    </span>
                </label>

                <label class="campo">
                    <span class="campo__etq">Fondo de apertura</span>
                    <input v-model="openForm.opening_float" type="text" inputmode="decimal" placeholder="0.00" required />
                    <span v-if="open.fieldErrors.value.opening_float" class="campo-error">
                        {{ open.fieldErrors.value.opening_float[0] }}
                    </span>
                </label>

                <p v-if="open.generalError.value" class="alerta alerta--error">{{ open.generalError.value }}</p>

                <div class="acciones">
                    <button type="submit" class="btn btn--acento" :disabled="open.processing.value">Abrir caja</button>
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
                            class="btn btn--peligro"
                            :disabled="close.processing.value || !isOpen"
                            @click="close.submit()"
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

                <p v-if="close.generalError.value" class="alerta alerta--error">{{ close.generalError.value }}</p>
                <p class="turno__pie nota">
                    Cerrar exige haber declarado el cierre. La diferencia entre lo declarado y lo esperado se asienta en
                    el diario financiero, con nombre, monto y actor.
                </p>
            </section>

            <!-- RESUMEN + CORTE: ambos salen del corte (permiso finance.cuts.view), del diario y nunca sumados aquí
                 (§6.9). Por eso viven bajo el mismo v-if: quien no puede ver el corte tampoco ve el resumen. -->
            <div v-if="cut" class="rejilla">
                <section class="tarjeta">
                    <header class="tarjeta__cab">
                        <span class="tarjeta__icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
                            </svg>
                        </span>
                        <h2>Resumen del turno</h2>
                    </header>

                    <dl class="resumen">
                        <div class="resumen__par"><dt>Ventas del turno</dt><dd>{{ money(cut.sales_total) }}</dd></div>
                        <div v-for="p in cut.payments_by_method" :key="p.method_ulid" class="resumen__par">
                            <dt>{{ p.method }}</dt><dd>{{ money(p.amount) }}</dd>
                        </div>
                        <div class="resumen__par"><dt>Gastos</dt><dd>{{ money(cut.expenses_total) }}</dd></div>
                        <div class="resumen__par"><dt>Retiros</dt><dd>{{ money(cut.withdrawals_total) }}</dd></div>
                        <div class="resumen__par"><dt>Fondo inicial</dt><dd>{{ money(session.opening_float) }}</dd></div>
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
                        <label class="campo">
                            <span class="campo__etq">Tipo de conteo</span>
                            <select v-model="declareForm.moment">
                                <option value="precount">Precorte</option>
                                <option value="close">Cierre</option>
                            </select>
                        </label>

                        <div class="montos">
                            <label v-for="m in methods" :key="m.ulid" class="campo">
                                <span class="campo__etq">{{ m.name }}</span>
                                <input
                                    v-model="declareForm.amounts[m.ulid]"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                />
                            </label>
                        </div>

                        <p v-if="declare.generalError.value" class="alerta alerta--error">{{ declare.generalError.value }}</p>

                        <div class="acciones">
                            <button type="submit" class="btn btn--acento" :disabled="declare.processing.value || !isOpen">
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
                        <label class="campo">
                            <span class="campo__etq">Monto</span>
                            <input v-model="withdrawForm.amount" type="text" inputmode="decimal" placeholder="0.00" required />
                        </label>

                        <label class="campo">
                            <span class="campo__etq">Motivo</span>
                            <input v-model="withdrawForm.reason" type="text" placeholder="Ej. Pago a proveedor" required />
                        </label>

                        <p v-if="withdrawError" class="alerta alerta--error">{{ withdrawError }}</p>

                        <div class="acciones">
                            <button type="submit" class="btn btn--acento" :disabled="withdrawProcesando || !isOpen">
                                Retirar
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </template>

        <!-- El PIN de un superior para el retiro: mismo diálogo que las demás acciones sensibles (ADR-008), y donde vive
             el teclado en pantalla. El 409 `authorization_required` lo abre; con la firma se reintenta el mismo retiro. -->
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

/* Resumen del turno: pares etiqueta/cifra en dos columnas, con el efectivo teórico destacado al pie. */
.resumen { display: grid; grid-template-columns: 1fr 1fr; gap: 0.15rem 1.5rem; margin: 0; }
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
    grid-column: 1 / -1;
    margin-top: 0.4rem;
    padding: 0.6rem 0.8rem;
    border: 1px solid color-mix(in srgb, var(--color-acento) 30%, transparent);
    border-radius: var(--radio-sm);
    background: color-mix(in srgb, var(--color-acento) 8%, transparent);
}
.resumen__par--destacado dt,
.resumen__par--destacado dd { color: var(--color-acento); }
@media (max-width: 32rem) { .resumen { grid-template-columns: 1fr; } }

/* Rejilla de dos columnas, colapsable. `stretch` iguala la altura de las dos tarjetas de cada fila (la más corta crece
   hasta la más alta) en vez de que cada una quede a la altura de su contenido. */
.rejilla { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; align-items: stretch; }
@media (max-width: 60rem) { .rejilla { grid-template-columns: 1fr; } }

.nota { color: var(--color-suave); font-size: 0.9rem; margin: 0 0 0.9rem; }
.nota--sola { margin: 0; }

.alerta { border-radius: var(--radio); padding: 0.6rem 0.8rem; font-size: 0.88rem; margin: 0 0 0.6rem; }
.alerta--error { color: var(--color-peligro); background: var(--color-peligro-tenue); }
.campo-error { color: var(--color-peligro); font-size: 0.82rem; }

/* Formularios */
.formulario { display: grid; gap: 0.85rem; }
.montos { display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr)); gap: 0.75rem; }
.campo { display: grid; gap: 0.3rem; font-size: 0.85rem; }
.campo__etq { color: var(--color-suave); }
.campo input,
.campo select {
    font: inherit;
    font-size: 0.9rem;
    padding: 0.55rem 0.65rem;
    border: 1px solid var(--color-borde);
    border-radius: var(--radio-sm);
    background: var(--color-superficie);
    color: var(--color-contenido);
}
.campo input:focus,
.campo select:focus { outline: none; border-color: var(--color-acento); box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-acento) 18%, transparent); }
.acciones { display: flex; justify-content: flex-end; }

/* Botones */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font: inherit;
    font-size: 0.92rem;
    font-weight: 600;
    padding: 0.6rem 1.15rem;
    border: 1px solid transparent;
    border-radius: var(--radio);
    box-shadow: var(--sombra-sm);
    cursor: pointer;
    transition: filter 0.15s ease, transform 0.15s ease;
}
.btn:hover:not(:disabled) { filter: brightness(1.06); transform: translateY(-1px); }
.btn:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
.btn--acento { background: var(--color-acento); color: var(--color-acento-texto); }
.btn--peligro { background: var(--color-peligro); color: #fff; }

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

@media (prefers-reduced-motion: reduce) {
    .btn:hover:not(:disabled) { transform: none; }
}
</style>
